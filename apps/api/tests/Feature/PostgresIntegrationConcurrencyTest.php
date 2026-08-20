<?php

namespace Tests\Feature;

use App\Contracts\Integrations\ReservationsImportPort;
use App\Contracts\Integrations\SecretReferenceResolver;
use App\Data\Integrations\IntegrationHealthResult;
use App\Data\Integrations\IntegrationItemResult;
use App\Data\Integrations\IntegrationPage;
use App\Data\Integrations\IntegrationServiceIdentity;
use App\Models\IntegrationConnection;
use App\Models\IntegrationDeadLetter;
use App\Models\IntegrationEvent;
use App\Models\IntegrationSyncCursor;
use App\Models\IntegrationSyncRun;
use App\Models\IntegrationSyncRunItem;
use App\Models\Membership;
use App\Models\Tenant;
use App\Services\IntegrationConnectionService;
use App\Services\Integrations\CapabilityPortRegistry;
use App\Services\Integrations\EndpointKeyService;
use App\Services\Integrations\IntegrationEventService;
use App\Services\Integrations\IntegrationRunService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;
use Throwable;

class PostgresIntegrationConcurrencyTest extends TestCase
{
    use CreatesTenant, DatabaseMigrations;

    protected function tearDown(): void
    {
        putenv('COMMERCIAL_TEST_TEARDOWN=1');
        try {
            parent::tearDown();
        } finally {
            putenv('COMMERCIAL_TEST_TEARDOWN');
        }
    }

    public function test_two_run_claimers_persist_one_page_and_one_cursor_transition(): void
    {
        $this->requirePostgresConcurrency();
        Queue::fake();
        [$tenant, $property, $user, $membership] = $this->tenantEnvironment();
        $connection = app(IntegrationConnectionService::class)->configure(
            'PG claim fake', 'webhook', [], 'env:PG_FAKE', $property->id,
            'pg_fake', 'reservations', 'account-1', 'sandbox', ['reservations.import'],
        );
        $connection = app(IntegrationConnectionService::class)->enable($connection, $user->id, 'Enable PG fake');
        app(CapabilityPortRegistry::class)->register('pg_fake', 'reservations', 'reservations.import', new PgReservationPort);
        $run = app(IntegrationRunService::class)->start($connection, 'reservations.import', $property->id, 'manual', 'pg-run-claim-000001', $user->id);

        $results = $this->concurrently([
            fn (): string => $this->executeRun($run->id),
            fn (): string => $this->executeRun($run->id),
        ], $tenant, $membership);
        app(TenantContext::class)->set($tenant, $membership);

        $this->assertSame(2, collect($results)->where('ok', true)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(1, $run->fresh()->attempt);
        $this->assertSame(1, $run->items()->count());
        $this->assertTrue($run->fresh()->page_in_progress);
        $this->assertSame(0, IntegrationSyncCursor::query()->sole()->version);
    }

    public function test_same_verified_event_race_creates_one_immutable_receipt(): void
    {
        $this->requirePostgresConcurrency();
        Queue::fake();
        [$tenant, $property, $user, $membership] = $this->tenantEnvironment();
        $secretBytes = str_repeat('p', 32);
        $secret = 'whsec_'.base64_encode($secretBytes);
        $this->app->bind(SecretReferenceResolver::class, fn () => new class($secret) implements SecretReferenceResolver
        {
            public function __construct(private string $secret) {}

            public function resolve(string $reference): string
            {
                return $this->secret;
            }
        });
        $connection = app(IntegrationConnectionService::class)->configure(
            'PG event fake', 'webhook', ['webhook_signing_secret_reference' => 'env:PG_WEBHOOK'], 'env:PG_FAKE', $property->id,
            'pg_fake', 'webhooks', 'account-1', 'sandbox', ['webhook.inbound'],
        );
        $connection = app(IntegrationConnectionService::class)->enable($connection, $user->id, 'Enable PG fake');
        $endpoint = app(EndpointKeyService::class)->rotate($connection, 0, $user->id, 'Issue endpoint')['key'];
        $raw = '{"id":"race-event","type":"reservation.changed","account_id":"account-1"}';
        $timestamp = (string) time();
        $headers = [
            'webhook-id' => 'race-message', 'webhook-timestamp' => $timestamp,
            'webhook-signature' => 'v1,'.base64_encode(hash_hmac('sha256', 'race-message.'.$timestamp.'.'.$raw, $secretBytes, true)),
        ];

        $results = $this->concurrently([
            fn (): string => app(IntegrationEventService::class)->receive($endpoint, $raw, $headers)->id,
            fn (): string => app(IntegrationEventService::class)->receive($endpoint, $raw, $headers)->id,
        ], $tenant, $membership);
        app(TenantContext::class)->set($tenant, $membership);

        $this->assertSame(2, collect($results)->where('ok', true)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(1, IntegrationEvent::query()->count());
        $this->assertCount(1, collect($results)->pluck('result')->unique());
    }

    public function test_same_persisted_item_race_invokes_the_capability_port_once(): void
    {
        $this->requirePostgresConcurrency();
        Queue::fake();
        [$tenant, $property, $user, $membership] = $this->tenantEnvironment();
        $connection = app(IntegrationConnectionService::class)->configure(
            'PG item fake', 'webhook', [], 'env:PG_FAKE', $property->id,
            'pg_fake', 'reservations', 'account-item-race', 'sandbox', ['reservations.import'],
        );
        $connection = app(IntegrationConnectionService::class)->enable($connection, $user->id, 'Enable PG fake');
        app(CapabilityPortRegistry::class)->register('pg_fake', 'reservations', 'reservations.import', new PgReservationPort);
        $run = app(IntegrationRunService::class)->start($connection, 'reservations.import', $property->id, 'manual', 'pg-item-race-000001', $user->id);
        app(IntegrationRunService::class)->executePage($run);
        $item = $run->items()->sole();

        $results = $this->concurrently([
            fn (): string => $this->processItem($item->id),
            fn (): string => $this->processItem($item->id),
        ], $tenant, $membership);
        app(TenantContext::class)->set($tenant, $membership);

        $this->assertSame(2, collect($results)->where('ok', true)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame('succeeded', $item->fresh()->status);
        $this->assertSame(1, $item->fresh()->attempt);
        $this->assertSame(1, IntegrationSyncCursor::query()->sole()->version);
    }

    public function test_cursor_restart_race_persists_the_next_page_once_and_advances_only_after_completion(): void
    {
        $this->requirePostgresConcurrency();
        Queue::fake();
        [$tenant, $property, $user, $membership] = $this->tenantEnvironment();
        $connection = app(IntegrationConnectionService::class)->configure(
            'PG cursor fake', 'webhook', [], 'env:PG_FAKE', $property->id,
            'pg_fake', 'reservations', 'account-cursor-race', 'sandbox', ['reservations.import'],
        );
        $connection = app(IntegrationConnectionService::class)->enable($connection, $user->id, 'Enable PG cursor fake');
        app(CapabilityPortRegistry::class)->register('pg_fake', 'reservations', 'reservations.import', new PgPagedReservationPort);
        $run = app(IntegrationRunService::class)->start($connection, 'reservations.import', $property->id, 'manual', 'pg-cursor-race-000001', $user->id);

        app(IntegrationRunService::class)->executePage($run);
        app(IntegrationRunService::class)->processItem($run->items()->sole());
        $this->assertSame(1, IntegrationSyncCursor::query()->sole()->version);
        $this->assertSame(['after' => 'page-1'], IntegrationSyncCursor::query()->sole()->checkpoint);

        $results = $this->concurrently([
            fn (): string => $this->executeRun($run->id),
            fn (): string => $this->executeRun($run->id),
        ], $tenant, $membership);
        app(TenantContext::class)->set($tenant, $membership);

        $this->assertSame(2, collect($results)->where('ok', true)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(2, $run->items()->count());
        $this->assertSame(1, IntegrationSyncCursor::query()->sole()->version);
        $this->assertSame(1, $run->items()->where('external_key', 'page-2')->count());
        app(IntegrationRunService::class)->processItem($run->items()->where('external_key', 'page-2')->sole());
        $this->assertSame(2, IntegrationSyncCursor::query()->sole()->version);
        $this->assertSame(['after' => 'page-2'], IntegrationSyncCursor::query()->sole()->checkpoint);
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_disable_after_item_claim_blocks_completion_and_cursor_commit(): void
    {
        $this->requirePostgresConcurrency();
        Queue::fake();
        [$tenant, $property, $user, $membership] = $this->tenantEnvironment();
        $connection = app(IntegrationConnectionService::class)->configure(
            'PG disable fake', 'webhook', [], 'env:PG_FAKE', $property->id,
            'pg_fake', 'reservations', 'account-disable-race', 'sandbox', ['reservations.import'],
        );
        $connection = app(IntegrationConnectionService::class)->enable($connection, $user->id, 'Enable PG disable fake');
        app(CapabilityPortRegistry::class)->register('pg_fake', 'reservations', 'reservations.import', new PgReservationPort);
        $run = app(IntegrationRunService::class)->start($connection, 'reservations.import', $property->id, 'manual', 'pg-disable-race-000001', $user->id);
        app(IntegrationRunService::class)->executePage($run);
        $item = $run->items()->sole();

        $results = $this->concurrently([
            fn (): string => $this->processItem($item->id),
            fn (): string => $this->disableAfterItemClaim($connection->id, $item->id, $user->id),
        ], $tenant, $membership);
        app(TenantContext::class)->set($tenant, $membership);

        $this->assertSame(2, collect($results)->where('ok', true)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame('blocked', $item->fresh()->status);
        $this->assertSame('blocked', $run->fresh()->status);
        $this->assertSame(0, IntegrationSyncCursor::query()->sole()->version);
        $this->assertFalse($connection->fresh()->is_enabled);
    }

    public function test_secret_rotation_during_claimed_request_preserves_the_in_flight_result(): void
    {
        $this->requirePostgresConcurrency();
        Queue::fake();
        [$tenant, $property, $user, $membership] = $this->tenantEnvironment();
        $connection = app(IntegrationConnectionService::class)->configure(
            'PG rotation fake', 'webhook', [], 'env:PG_FAKE_OLD', $property->id,
            'pg_fake', 'reservations', 'account-rotation-race', 'sandbox', ['reservations.import'],
        );
        $connection = app(IntegrationConnectionService::class)->enable($connection, $user->id, 'Enable PG rotation fake');
        app(CapabilityPortRegistry::class)->register('pg_fake', 'reservations', 'reservations.import', new PgSecretObservationPort);
        $run = app(IntegrationRunService::class)->start($connection, 'reservations.import', $property->id, 'manual', 'pg-rotation-race-000001', $user->id);
        app(IntegrationRunService::class)->executePage($run);
        $item = $run->items()->sole();

        $results = $this->concurrently([
            fn (): string => $this->processItem($item->id),
            fn (): string => $this->rotateAfterItemClaim($connection->id, $item->id, $user->id),
        ], $tenant, $membership);
        app(TenantContext::class)->set($tenant, $membership);

        $this->assertSame(2, collect($results)->where('ok', true)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame('succeeded', $item->fresh()->status);
        $this->assertSame('secret:'.hash('sha256', 'env:PG_FAKE_OLD'), $item->fresh()->local_key);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame('env:PG_FAKE_NEW', $connection->fresh()->secret_reference);
        $this->assertSame(2, $connection->fresh()->configuration_version);
        $this->assertSame(1, IntegrationSyncCursor::query()->sole()->version);
    }

    public function test_replay_race_allows_only_one_transition_while_the_original_is_inactive(): void
    {
        $this->requirePostgresConcurrency();
        Queue::fake();
        [$tenant, $property, $user, $membership] = $this->tenantEnvironment();
        $connection = app(IntegrationConnectionService::class)->configure(
            'PG replay fake', 'webhook', [], 'env:PG_FAKE', $property->id,
            'pg_fake', 'reservations', 'account-replay-race', 'sandbox', ['reservations.import'],
        );
        $connection = app(IntegrationConnectionService::class)->enable($connection, $user->id, 'Enable PG replay fake');
        app(CapabilityPortRegistry::class)->register('pg_fake', 'reservations', 'reservations.import', new PgReservationPort);
        $run = app(IntegrationRunService::class)->start($connection, 'reservations.import', $property->id, 'manual', 'pg-replay-race-000001', $user->id);
        app(IntegrationRunService::class)->executePage($run);
        $item = $run->items()->sole();
        app(IntegrationRunService::class)->processItem($item);
        $letter = IntegrationDeadLetter::query()->create([
            'integration_connection_id' => $connection->id,
            'property_id' => $property->id,
            'integration_sync_run_item_id' => $item->id,
            'reason_code' => 'race_fixture',
            'safe_error' => 'Redacted replay fixture.',
            'status' => 'open',
        ]);

        $results = $this->concurrently([
            fn (): string => $this->replay($letter->id, $user->id),
            fn (): string => $this->replay($letter->id, $user->id),
        ], $tenant, $membership);
        app(TenantContext::class)->set($tenant, $membership);

        $this->assertSame(1, collect($results)->where('ok', true)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(1, collect($results)->where('ok', false)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame('replaying', $letter->fresh()->status);
        $this->assertSame(1, $letter->fresh()->replay_count);
        $this->assertSame('pending', $item->fresh()->status);
    }

    private function executeRun(string $runId): string
    {
        app(IntegrationRunService::class)->executePage(IntegrationSyncRun::query()->findOrFail($runId));

        return $runId;
    }

    private function processItem(string $itemId): string
    {
        app(IntegrationRunService::class)->processItem(IntegrationSyncRunItem::query()->findOrFail($itemId));

        return $itemId;
    }

    private function disableAfterItemClaim(string $connectionId, string $itemId, int $actorId): string
    {
        $this->waitUntilItemStatus($itemId, 'processing');
        app(IntegrationConnectionService::class)->disable(IntegrationConnection::query()->findOrFail($connectionId), $actorId, 'PG after-claim disable race.');

        return $connectionId;
    }

    private function rotateAfterItemClaim(string $connectionId, string $itemId, int $actorId): string
    {
        $this->waitUntilItemStatus($itemId, 'processing');
        app(IntegrationConnectionService::class)->rotateSecretReference(
            IntegrationConnection::query()->findOrFail($connectionId), 'env:PG_FAKE_NEW', $actorId, 'PG in-flight rotation race.',
        );

        return $connectionId;
    }

    private function replay(string $letterId, int $actorId): string
    {
        app(IntegrationRunService::class)->replay(IntegrationDeadLetter::query()->findOrFail($letterId), $actorId, 'PG replay race.');

        return $letterId;
    }

    private function waitUntilItemStatus(string $itemId, string $status): void
    {
        $deadline = microtime(true) + 5;
        do {
            if (IntegrationSyncRunItem::query()->whereKey($itemId)->value('status') === $status) {
                return;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        throw new \RuntimeException("Timed out waiting for item {$itemId} to enter {$status}.");
    }

    /** @param array<int, callable(): string> $operations @return array<int, array{ok:bool,result?:string,error?:string}> */
    private function concurrently(array $operations, Tenant $tenant, Membership $membership): array
    {
        $directory = sys_get_temp_dir().'/inn-integration-race-'.Str::random(12);
        mkdir($directory, 0700, true);
        $barrier = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($barrier === false) {
            $this->fail('Unable to create the integration concurrency barrier.');
        }
        $children = [];
        foreach ($operations as $index => $operation) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('Unable to fork an integration concurrency worker.');
            }
            if ($pid === 0) {
                fclose($barrier[0]);
                fread($barrier[1], 1);
                try {
                    DB::purge();
                    DB::reconnect();
                    app(TenantContext::class)->set($tenant, $membership);
                    Queue::fake();
                    $payload = ['ok' => true, 'result' => $operation()];
                } catch (Throwable $exception) {
                    $payload = ['ok' => false, 'error' => $exception::class.': '.$exception->getMessage()];
                }
                file_put_contents("{$directory}/{$index}.json", json_encode($payload, JSON_THROW_ON_ERROR));
                exit(0);
            }
            $children[] = $pid;
        }
        fclose($barrier[1]);
        fwrite($barrier[0], str_repeat('1', count($operations)));
        fclose($barrier[0]);
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0);
        }
        DB::purge();
        DB::reconnect();
        $results = [];
        foreach (array_keys($operations) as $index) {
            $results[] = json_decode((string) file_get_contents("{$directory}/{$index}.json"), true, flags: JSON_THROW_ON_ERROR);
            unlink("{$directory}/{$index}.json");
        }
        rmdir($directory);

        return $results;
    }

    private function requirePostgresConcurrency(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL integration races are exercised by the PostgreSQL gate.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('The PostgreSQL integration race gate requires pcntl.');
        }
    }
}

final class PgReservationPort implements ReservationsImportPort
{
    public function test(IntegrationConnection $connection): IntegrationHealthResult
    {
        return new IntegrationHealthResult(true, 1);
    }

    public function fetchPage(IntegrationConnection $connection, ?array $checkpoint): IntegrationPage
    {
        usleep(150_000);

        return new IntegrationPage([
            ['external_key' => 'race-item', 'checksum' => hash('sha256', 'race-item')],
        ], ['after' => 'race-item'], false);
    }

    public function importReservation(IntegrationConnection $connection, string $externalKey, IntegrationServiceIdentity $identity, string $idempotencyKey): IntegrationItemResult
    {
        usleep(300_000);

        return new IntegrationItemResult('reservation:'.$externalKey);
    }
}

final class PgPagedReservationPort implements ReservationsImportPort
{
    public function test(IntegrationConnection $connection): IntegrationHealthResult
    {
        return new IntegrationHealthResult(true, 1);
    }

    public function fetchPage(IntegrationConnection $connection, ?array $checkpoint): IntegrationPage
    {
        usleep(150_000);
        $page = $checkpoint === null ? 1 : 2;

        return new IntegrationPage([
            ['external_key' => 'page-'.$page, 'checksum' => hash('sha256', 'page-'.$page)],
        ], ['after' => 'page-'.$page], $page === 1);
    }

    public function importReservation(IntegrationConnection $connection, string $externalKey, IntegrationServiceIdentity $identity, string $idempotencyKey): IntegrationItemResult
    {
        return new IntegrationItemResult('reservation:'.$externalKey);
    }
}

final class PgSecretObservationPort implements ReservationsImportPort
{
    public function test(IntegrationConnection $connection): IntegrationHealthResult
    {
        return new IntegrationHealthResult(true, 1);
    }

    public function fetchPage(IntegrationConnection $connection, ?array $checkpoint): IntegrationPage
    {
        return new IntegrationPage([
            ['external_key' => 'secret-observation', 'checksum' => hash('sha256', 'secret-observation')],
        ], ['after' => 'secret-observation'], false);
    }

    public function importReservation(IntegrationConnection $connection, string $externalKey, IntegrationServiceIdentity $identity, string $idempotencyKey): IntegrationItemResult
    {
        $requestSecretReference = $connection->secret_reference;
        usleep(300_000);
        if ($connection->secret_reference !== $requestSecretReference) {
            throw new \RuntimeException('An in-flight request changed its configuration snapshot.');
        }

        return new IntegrationItemResult('secret:'.hash('sha256', (string) $requestSecretReference));
    }
}
