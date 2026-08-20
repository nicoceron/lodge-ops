<?php

namespace Tests\Feature\Integrations;

use App\Contracts\Integrations\ReservationsImportPort;
use App\Contracts\Integrations\SecretReferenceResolver;
use App\Data\Integrations\IntegrationHealthResult;
use App\Data\Integrations\IntegrationItemResult;
use App\Data\Integrations\IntegrationPage;
use App\Data\Integrations\IntegrationServiceIdentity;
use App\Exceptions\Integrations\PoisonIntegrationException;
use App\Jobs\ExecuteIntegrationRunJob;
use App\Jobs\ProcessIntegrationEventJob;
use App\Models\IntegrationConnection;
use App\Models\IntegrationDeadLetter;
use App\Models\IntegrationEndpointKey;
use App\Models\IntegrationEvent;
use App\Models\IntegrationOperation;
use App\Models\IntegrationSyncCursor;
use App\Models\IntegrationSyncRunItem;
use App\Models\Property;
use App\Services\IntegrationConnectionService;
use App\Services\Integrations\CapabilityPortRegistry;
use App\Services\Integrations\EndpointKeyService;
use App\Services\Integrations\IntegrationEventService;
use App\Services\Integrations\IntegrationHealthService;
use App\Services\Integrations\IntegrationMappingService;
use App\Services\Integrations\IntegrationOperationRecorder;
use App\Services\Integrations\IntegrationRunService;
use App\Services\Integrations\SafeIntegrationError;
use App\Services\Integrations\StandardWebhookVerifier;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class IntegrationKernelTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_endpoint_keys_are_hashed_rotatable_overlapping_and_revocable(): void
    {
        [, $property, $user] = $this->tenantEnvironment();
        $raw = str_repeat('k', 48);
        $connection = IntegrationConnection::query()->create([
            'name' => 'Legacy payment', 'type' => 'payment', 'property_id' => $property->id,
            'provider' => 'mercado_pago', 'product' => 'checkout_pro', 'external_account_id' => 'merchant-1',
            'environment' => 'sandbox', 'status' => 'configured', 'secret_reference' => 'env:MP_ACCESS_TOKEN',
            'configuration' => ['webhook_key' => $raw, 'provider_account' => 'merchant-1'],
            'capabilities' => ['payment.hosted_checkout'],
        ]);

        $this->assertSame(hash('sha256', $raw), $connection->fresh()->getRawOriginal('payment_webhook_key'));
        $this->assertArrayNotHasKey('webhook_key', $connection->fresh()->configuration);
        $this->assertSame(1, IntegrationEndpointKey::query()->count());
        $this->assertSame($connection->id, app(EndpointKeyService::class)->resolveConnection($raw)->id);

        $issued = app(EndpointKeyService::class)->rotate($connection->fresh(), 15, $user->id, 'Scheduled rotation', 'endpoint-rotation-0001');
        $this->assertSame(2, $issued['version']);
        $this->assertSame(64, strlen($issued['key']));
        $this->assertNull($connection->fresh()->legacy_endpoint_key_ciphertext);
        $this->assertSame($connection->id, app(EndpointKeyService::class)->resolveConnection($raw)->id);
        $this->assertSame($connection->id, app(EndpointKeyService::class)->resolveConnection($issued['key'])->id);
        $this->assertDatabaseMissing('idempotency_keys', ['key' => 'endpoint-rotation-0001']);
        $this->expectException(DomainException::class);
        app(EndpointKeyService::class)->rotate($connection->fresh(), 15, $user->id, 'Duplicate rotation', 'endpoint-rotation-0001');
    }

    public function test_connection_model_rejects_divergent_configuration_identity(): void
    {
        [, $property] = $this->tenantEnvironment();
        $this->expectException(DomainException::class);
        IntegrationConnection::query()->create([
            'name' => 'Divergent MP', 'type' => 'payment', 'property_id' => $property->id,
            'provider' => 'mercado_pago', 'product' => 'checkout_pro', 'external_account_id' => 'canonical-account',
            'environment' => 'sandbox', 'configuration' => ['provider_account' => 'different-account'],
        ]);
    }

    public function test_revoke_blocks_every_endpoint_version_without_persisting_raw_keys(): void
    {
        [, $property, $user] = $this->tenantEnvironment();
        $connection = $this->connection($property->id, ['webhook.inbound']);
        $first = app(EndpointKeyService::class)->rotate($connection, 10, $user->id, 'First endpoint');
        $second = app(EndpointKeyService::class)->rotate($connection->fresh(), 10, $user->id, 'Second endpoint');
        app(EndpointKeyService::class)->revokeAll($connection->fresh(), $user->id, 'Connection retired');

        $this->assertSame(2, IntegrationEndpointKey::query()->whereNotNull('revoked_at')->count());
        $this->assertDatabaseMissing('integration_connections', ['id' => $connection->id, 'payment_webhook_key' => $first['key']]);
        $this->assertDatabaseMissing('integration_connections', ['id' => $connection->id, 'payment_webhook_key' => $second['key']]);
        $this->expectException(ModelNotFoundException::class);
        app(EndpointKeyService::class)->resolveConnection($second['key']);
    }

    public function test_cursor_commits_after_mixed_terminal_outcomes_and_replay_never_rewinds_it(): void
    {
        Queue::fake();
        [, $property, $user] = $this->tenantEnvironment();
        $connection = $this->connection($property->id, ['reservations.import']);
        $port = new FakeReservationPort;
        app(CapabilityPortRegistry::class)->register('contract_fake', 'reservations', 'reservations.import', $port);
        $runs = app(IntegrationRunService::class);
        $run = $runs->start($connection, 'reservations.import', $property->id, 'manual', 'run-command-00000001', $user->id);
        $runs->executePage($run);

        $this->assertSame(1, $port->fetchCalls);
        $this->assertTrue($run->fresh()->page_in_progress);
        $runs->executePage($run->fresh());
        $this->assertSame(1, $port->fetchCalls, 'Crash recovery must redispatch the persisted page, not fetch beyond its cursor.');

        $runs->processItem($run->items()->where('external_key', 'poison-1')->sole());
        $activeLetter = IntegrationDeadLetter::query()->sole();
        try {
            $runs->replay($activeLetter, $user->id, 'Premature replay');
            $this->fail('A dead letter cannot replay while its original page is active.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
        $runs->processItem($run->items()->where('external_key', 'good-1')->sole());
        $cursor = IntegrationSyncCursor::query()->sole();
        $this->assertSame(['after' => 'page-1'], $cursor->checkpoint);
        $this->assertSame(1, $cursor->version);
        $this->assertSame('completed', $run->fresh()->status);
        $letter = IntegrationDeadLetter::query()->sole();
        $this->assertSame('poison_item', $letter->reason_code);

        $port->poison = false;
        $runs->replay($letter, $user->id, 'Mapping corrected');
        $runs->processItem($letter->item->fresh());
        $this->assertSame(1, $cursor->fresh()->version, 'A completed-page replay must not commit or rewind the cursor again.');
        $this->assertSame('resolved', $letter->fresh()->status);
        $this->assertSame(2, $run->fresh()->success_count);
    }

    public function test_run_idempotency_rejects_same_key_with_different_facts(): void
    {
        Queue::fake();
        [, $property, $user] = $this->tenantEnvironment();
        $connection = $this->connection($property->id, ['reservations.import']);
        $runs = app(IntegrationRunService::class);
        $first = $runs->start($connection, 'reservations.import', $property->id, 'manual', 'stable-run-command-1', $user->id);
        $this->assertSame($first->id, $runs->start($connection, 'reservations.import', $property->id, 'manual', 'stable-run-command-1', $user->id)->id);

        $this->expectException(DomainException::class);
        $runs->start($connection, 'reservations.import', $property->id, 'scheduled', 'stable-run-command-1', $user->id);
    }

    public function test_disable_blocks_claimed_page_and_explicit_resume_preserves_run_page_and_cursor(): void
    {
        Queue::fake();
        [, $property, $user] = $this->tenantEnvironment();
        $connection = $this->connection($property->id, ['reservations.import']);
        $port = new FakeReservationPort;
        $port->poison = false;
        app(CapabilityPortRegistry::class)->register('contract_fake', 'reservations', 'reservations.import', $port);
        $runs = app(IntegrationRunService::class);
        $run = $runs->start($connection, 'reservations.import', $property->id, 'manual', 'disable-resume-run-0001', $user->id);
        $runs->executePage($run);
        $this->assertSame(1, $run->fresh()->page_number);
        app(IntegrationConnectionService::class)->disable($connection->fresh(), $user->id, 'Fence active integration work.');
        $this->assertSame('blocked', $run->fresh()->status);
        $this->assertSame(['blocked'], $run->items()->pluck('status')->unique()->values()->all());
        $this->assertSame(0, IntegrationSyncCursor::query()->sole()->version);

        $enabled = app(IntegrationConnectionService::class)->enable($connection->fresh(), $user->id, 'Enable for explicit resume.');
        $resumed = $runs->resume($run->fresh(), 'resume-blocked-run-0001', $user->id, 'Resume the specifically blocked run.');
        $this->assertSame($run->id, $resumed->id);
        $this->assertSame(1, $resumed->page_number);
        $this->assertSame(['pending'], $resumed->items()->pluck('status')->unique()->values()->all());
        $this->assertSame(0, IntegrationSyncCursor::query()->sole()->version);
        $this->assertSame($resumed->id, $runs->resume($resumed->fresh(), 'resume-blocked-run-0001', $user->id, 'Idempotent retry.')->id);
        $runs->executePage($resumed->fresh());
        $this->assertSame(1, $port->fetchCalls, 'Resume must redispatch the persisted page instead of fetching past its cursor.');
        foreach ($resumed->items as $item) {
            $runs->processItem($item->fresh());
        }
        $this->assertSame('completed', $resumed->fresh()->status);
        $this->assertSame(1, IntegrationSyncCursor::query()->sole()->version);
        $this->assertTrue($enabled->is_enabled);
    }

    public function test_mapping_drift_versions_facts_and_opens_reconciliation(): void
    {
        [, $property] = $this->tenantEnvironment();
        $connection = $this->connection($property->id, ['reservations.import']);
        $service = app(IntegrationMappingService::class);
        $first = $service->version($connection, $property->id, 'reservations.import', 'inbound', 'reservation', 'local-1', 'booking', 'external-1', 1, ['status' => 'confirmed']);
        $second = $service->version($connection, $property->id, 'reservations.import', 'inbound', 'reservation', 'local-2', 'booking', 'external-1', 2, ['status' => 'confirmed']);

        $this->assertSame('drift', $first->fresh()->conflict_state);
        $this->assertNotNull($first->fresh()->valid_until);
        $this->assertSame('drift', $second->conflict_state);
        $this->assertDatabaseHas('integration_reconciliations', ['kind' => 'mapping_drift', 'external_key' => 'external-1', 'status' => 'open']);
    }

    public function test_heartbeat_recovers_expired_item_and_event_worker_leases_without_skipping_the_page(): void
    {
        Queue::fake();
        [, $property, $user] = $this->tenantEnvironment();
        $connection = $this->connection($property->id, ['reservations.import']);
        app(CapabilityPortRegistry::class)->register('contract_fake', 'reservations', 'reservations.import', new FakeReservationPort);
        $run = app(IntegrationRunService::class)->start(
            $connection, 'reservations.import', $property->id, 'manual', 'worker-crash-recovery-1', $user->id,
        );
        app(IntegrationRunService::class)->executePage($run);
        $item = $run->items()->firstOrFail();
        $item->update(['status' => 'processing', 'started_at' => now()->subMinutes(3)]);
        $event = IntegrationEvent::query()->create([
            'integration_connection_id' => $connection->id,
            'property_id' => $property->id,
            'capability' => 'webhook.inbound',
            'external_id' => 'expired-event-worker',
            'event_type' => 'reservation.changed',
            'external_version' => '1',
            'raw_checksum' => hash('sha256', 'expired-event-worker'),
            'disposition' => 'processing',
            'received_at' => now()->subMinutes(4),
        ]);
        $event->forceFill(['updated_at' => now()->subMinutes(3)])->save();

        Artisan::call('integrations:heartbeat');

        $this->assertSame('retryable', $item->fresh()->status);
        $this->assertSame('retryable', $event->fresh()->disposition);
        $this->assertSame(0, IntegrationSyncCursor::query()->sole()->version);
        Queue::assertPushed(ExecuteIntegrationRunJob::class, fn ($job): bool => $job->runId === $run->id);
        Queue::assertPushed(ProcessIntegrationEventJob::class, fn ($job): bool => $job->eventId === $event->id);
    }

    public function test_health_snapshots_and_scheduler_gauges_are_isolated_by_tenant_and_property_scope(): void
    {
        Queue::fake();
        [$tenant, $property, $user, $membership] = $this->tenantEnvironment();
        $otherProperty = Property::factory()->create();
        app(TenantContext::class)->set($tenant);
        $first = app(IntegrationConnectionService::class)->configure(
            'First health scope', 'webhook', [], 'env:HEALTH_FIRST', $property->id,
            'contract_fake', 'reservations', 'health-first', 'sandbox', ['reservations.import'],
        );
        $first = app(IntegrationConnectionService::class)->enable($first, $user->id, 'Enable first health scope.');
        $second = app(IntegrationConnectionService::class)->configure(
            'Second health scope', 'webhook', [], 'env:HEALTH_SECOND', $otherProperty->id,
            'contract_fake', 'reservations', 'health-second', 'sandbox', ['reservations.import'],
        );
        $second = app(IntegrationConnectionService::class)->enable($second, $user->id, 'Enable second health scope.');
        $runs = app(IntegrationRunService::class);
        $firstRun = $runs->start($first, 'reservations.import', $property->id, 'manual', 'health-first-run-0001', $user->id);
        $secondRun = $runs->start($second, 'reservations.import', $otherProperty->id, 'manual', 'health-second-run-001', $user->id);
        foreach ([[$firstRun, 1], [$secondRun, 2]] as [$run, $count]) {
            for ($index = 0; $index < $count; $index++) {
                IntegrationSyncRunItem::query()->create([
                    'integration_sync_run_id' => $run->id, 'property_id' => $run->property_id, 'page_number' => 1,
                    'external_key' => $run->id.'-'.$index, 'payload_checksum' => hash('sha256', $run->id.'-'.$index),
                    'status' => 'pending', 'idempotency_key' => hash('sha256', 'health-'.$run->id.'-'.$index),
                ]);
            }
        }
        Artisan::call('integrations:heartbeat');

        app(TenantContext::class)->set($tenant, $membership);
        $firstSnapshot = app(IntegrationHealthService::class)->snapshot($first);
        $this->assertSame(1, $firstSnapshot['backlog']);
        $this->assertSame(1, data_get($firstSnapshot, 'scheduler_heartbeat.backlog_items'));
        $membership->update(['property_id' => $otherProperty->id]);
        app(TenantContext::class)->set($tenant, $membership->fresh());
        $secondSnapshot = app(IntegrationHealthService::class)->snapshot($second);
        $this->assertSame(2, $secondSnapshot['backlog']);
        $this->assertSame(2, data_get($secondSnapshot, 'scheduler_heartbeat.backlog_items'));
    }

    public function test_invalid_auth_health_and_partial_page_fail_safely_without_cursor_advance(): void
    {
        Queue::fake();
        [, $property, $user] = $this->tenantEnvironment();
        $connection = $this->connection($property->id, ['reservations.import']);
        app(CapabilityPortRegistry::class)->register('contract_fake', 'reservations', 'reservations.import', new PartialPageReservationPort);

        $health = app(IntegrationHealthService::class)->test($connection, 'reservations.import');
        $this->assertFalse($health->healthy);
        $this->assertSame('degraded', $connection->fresh()->health_status);
        $this->assertSame('Authentication was rejected by the test provider.', $connection->fresh()->last_error);

        $run = app(IntegrationRunService::class)->start(
            $connection->fresh(), 'reservations.import', $property->id, 'manual', 'partial-page-contract-1', $user->id,
        );
        try {
            app(IntegrationRunService::class)->executePage($run);
            $this->fail('A partial page without a continuation checkpoint must fail closed.');
        } catch (PoisonIntegrationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame(0, IntegrationSyncCursor::query()->sole()->version);
        $this->assertDatabaseHas('integration_reconciliations', ['kind' => 'run_page_failure', 'reason_code' => 'malformed_page']);
    }

    public function test_standard_webhook_exact_raw_signature_duplicate_unknown_account_and_immutability(): void
    {
        Queue::fake();
        [, $property] = $this->tenantEnvironment();
        $secretBytes = str_repeat('s', 32);
        $secret = 'whsec_'.base64_encode($secretBytes);
        $this->app->bind(SecretReferenceResolver::class, fn () => new class($secret) implements SecretReferenceResolver
        {
            public function __construct(private string $secret) {}

            public function resolve(string $reference): string
            {
                return $this->secret;
            }
        });
        $connection = $this->connection($property->id, ['webhook.inbound'], [
            'webhook_signing_secret_reference' => 'env:STANDARD_WEBHOOK_SECRET',
        ]);
        $endpoint = app(EndpointKeyService::class)->rotate($connection, 0, null, 'Webhook endpoint')['key'];
        $raw = '{"id":"evt-1","type":"reservation.changed","version":"7","account_id":"account-1"}';
        $headers = $this->signedHeaders($raw, $secretBytes, 'msg-1');
        $events = app(IntegrationEventService::class);
        $event = $events->receive($endpoint, $raw, $headers);
        $duplicate = $events->receive($endpoint, $raw, $headers);
        $this->assertSame($event->id, $duplicate->id);
        $this->assertSame(1, IntegrationEvent::query()->count());
        $this->assertSame(hash('sha256', $raw), $event->raw_checksum);

        $collisionRaw = '{"id":"evt-reused","type":"reservation.changed","version":"7","account_id":"account-1"}';
        $collision = $events->receive($endpoint, $collisionRaw, $this->signedHeaders($collisionRaw, $secretBytes, 'msg-1'));
        $this->assertSame($event->id, $collision->id);
        $this->assertDatabaseHas('integration_reconciliations', [
            'kind' => 'event_identity_collision',
            'external_key' => 'msg-1',
            'reason_code' => 'signed_event_identity_reused',
        ]);

        $unknownRaw = '{"id":"evt-2","type":"reservation.changed","account_id":"other-account"}';
        $unknown = $events->receive($endpoint, $unknownRaw, $this->signedHeaders($unknownRaw, $secretBytes, 'msg-2'));
        $this->assertSame('unmatched', $unknown->disposition);
        $this->assertDatabaseHas('integration_reconciliations', ['kind' => 'unknown_external_account', 'external_key' => 'msg-2']);

        try {
            $event->forceFill(['raw_checksum' => str_repeat('0', 64)])->save();
            $this->fail('Verified event facts must be immutable.');
        } catch (LogicException) {
        }
        $this->assertSame(hash('sha256', $raw), $event->fresh()->raw_checksum);
    }

    public function test_standard_webhook_rejects_missing_invalid_and_stale_signatures(): void
    {
        $verifier = app(StandardWebhookVerifier::class);
        foreach ([
            [],
            ['webhook-id' => 'msg', 'webhook-timestamp' => (string) time(), 'webhook-signature' => 'v1,invalid'],
            $this->signedHeaders('{}', str_repeat('s', 32), 'msg', time() - 301),
        ] as $headers) {
            try {
                $verifier->verify('{}', $headers, 'whsec_'.base64_encode(str_repeat('s', 32)));
                $this->fail('Invalid Standard Webhooks signatures must fail closed.');
            } catch (\RuntimeException) {
            }
        }
        $this->addToAssertionCount(3);
    }

    public function test_standard_webhook_rejects_decoded_secrets_outside_24_to_64_bytes(): void
    {
        $verifier = app(StandardWebhookVerifier::class);
        foreach ([str_repeat('s', 23), str_repeat('s', 65)] as $bytes) {
            $headers = $this->signedHeaders('{}', $bytes, 'length-test');
            try {
                $verifier->verify('{}', $headers, 'whsec_'.base64_encode($bytes));
                $this->fail('Decoded Standard Webhooks secrets outside 24-64 bytes must fail closed.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('malformed', $exception->getMessage());
            }
        }
    }

    public function test_adversarial_credentials_are_redacted_across_connection_run_item_event_dead_letter_and_audit_sinks(): void
    {
        Queue::fake();
        [, $property, $user] = $this->tenantEnvironment();
        $connection = $this->connection($property->id, ['reservations.import']);
        $message = 'token=token-value secret:secret-value password="password value with spaces" api-key=api-key-value '
            .'Bearer bearer-value Basic basic-value https://user:pass@provider.example/path?token=url-value '
            .'{"access_token":"json-token-value","client_secret":"json-secret-value"}';
        $redacted = SafeIntegrationError::from($message);
        $secretValues = [
            'token-value', 'secret-value', 'password value with spaces', 'api-key-value', 'bearer-value',
            'basic-value', 'user:pass', 'url-value', 'json-token-value', 'json-secret-value',
        ];
        foreach ($secretValues as $secret) {
            $this->assertStringNotContainsString($secret, $redacted);
        }

        $run = app(IntegrationRunService::class)->start(
            $connection, 'reservations.import', $property->id, 'manual', 'safe-sink-run-000001', $user->id,
        );
        $run->update(['last_error' => $message]);
        $item = IntegrationSyncRunItem::query()->create([
            'integration_sync_run_id' => $run->id, 'property_id' => $property->id, 'page_number' => 1,
            'external_key' => 'safe-sink-item', 'payload_checksum' => hash('sha256', 'safe-sink-item'),
            'safe_payload' => ['note' => $message, 'api_key' => 'nested-api-key-value'], 'status' => 'dead_letter',
            'idempotency_key' => hash('sha256', 'safe-sink-item'), 'last_error' => $message,
        ]);
        $event = IntegrationEvent::query()->create([
            'integration_connection_id' => $connection->id, 'property_id' => $property->id, 'capability' => 'webhook.inbound',
            'external_id' => 'safe-sink-event', 'event_type' => 'test', 'external_version' => '1',
            'raw_checksum' => hash('sha256', 'safe-sink-event'), 'safe_snapshot' => ['subject' => $message, 'token' => 'event-token-value'],
            'disposition' => 'dead_letter', 'received_at' => now(), 'last_error' => $message,
        ]);
        $letter = IntegrationDeadLetter::query()->create([
            'integration_connection_id' => $connection->id, 'property_id' => $property->id,
            'integration_sync_run_item_id' => $item->id, 'reason_code' => 'safe_sink', 'safe_error' => $message, 'status' => 'open',
        ]);
        $connection->update(['last_error' => $message]);
        $operation = IntegrationOperationRecorder::record($connection, 'safe_sink_test', $user->id, $message, [
            'message' => $message, 'password' => 'audit-password-value',
        ]);
        foreach ([
            $connection->fresh()->last_error, $run->fresh()->last_error, $item->fresh()->last_error,
            json_encode($item->fresh()->safe_payload), $event->fresh()->last_error, json_encode($event->fresh()->safe_snapshot),
            $letter->fresh()->safe_error, $operation->fresh()->reason, json_encode($operation->fresh()->safe_facts),
        ] as $sink) {
            foreach ([...$secretValues, 'nested-api-key-value', 'event-token-value', 'audit-password-value'] as $secret) {
                $this->assertStringNotContainsString($secret, (string) $sink);
            }
            $this->assertStringContainsString('[redacted', (string) $sink);
        }
        $this->assertSame(1, IntegrationOperation::query()->whereKey($operation->id)->count());
    }

    private function connection(string $propertyId, array $capabilities, array $configuration = []): IntegrationConnection
    {
        $connection = app(IntegrationConnectionService::class)->configure(
            'Contract fake', 'webhook', $configuration, 'env:CONTRACT_FAKE_SECRET', $propertyId,
            'contract_fake', $capabilities === ['reservations.import'] ? 'reservations' : 'webhooks', 'account-1', 'sandbox', $capabilities,
        );

        return app(IntegrationConnectionService::class)->enable($connection, auth()->id(), 'Enable contract fake');
    }

    /** @return array<string,string> */
    private function signedHeaders(string $raw, string $secretBytes, string $id, ?int $timestamp = null): array
    {
        $timestamp ??= time();

        return [
            'webhook-id' => $id,
            'webhook-timestamp' => (string) $timestamp,
            'webhook-signature' => 'v1,'.base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$raw, $secretBytes, true)),
        ];
    }
}

final class FakeReservationPort implements ReservationsImportPort
{
    public int $fetchCalls = 0;

    public bool $poison = true;

    public function test(IntegrationConnection $connection): IntegrationHealthResult
    {
        return new IntegrationHealthResult(true, 5, 0, 'Contract fake reachable.');
    }

    public function fetchPage(IntegrationConnection $connection, ?array $checkpoint): IntegrationPage
    {
        $this->fetchCalls++;

        return new IntegrationPage([
            ['external_key' => 'good-1', 'checksum' => hash('sha256', 'good-1'), 'safe_snapshot' => ['kind' => 'reservation']],
            ['external_key' => 'poison-1', 'checksum' => hash('sha256', 'poison-1'), 'safe_snapshot' => ['kind' => 'reservation']],
        ], ['after' => 'page-1'], false);
    }

    public function importReservation(IntegrationConnection $connection, string $externalKey, IntegrationServiceIdentity $identity, string $idempotencyKey): IntegrationItemResult
    {
        if (! $identity->allows('reservations.import') || $identity->propertyId !== $connection->property_id) {
            throw new DomainException('System identity exceeded its capability or property scope.');
        }
        if ($externalKey === 'poison-1' && $this->poison) {
            throw new PoisonIntegrationException('Unknown room mapping; bearer top-secret must not leak.');
        }

        return new IntegrationItemResult('reservation:'.$externalKey, 200, 12, hash('sha256', $idempotencyKey), hash('sha256', $externalKey));
    }
}

final class PartialPageReservationPort implements ReservationsImportPort
{
    public function test(IntegrationConnection $connection): IntegrationHealthResult
    {
        return new IntegrationHealthResult(false, 1, null, 'Authentication was rejected by the test provider.');
    }

    public function fetchPage(IntegrationConnection $connection, ?array $checkpoint): IntegrationPage
    {
        return new IntegrationPage([
            ['external_key' => 'partial-1', 'checksum' => hash('sha256', 'partial-1')],
        ], null, true);
    }

    public function importReservation(IntegrationConnection $connection, string $externalKey, IntegrationServiceIdentity $identity, string $idempotencyKey): IntegrationItemResult
    {
        throw new LogicException('The malformed page must never dispatch items.');
    }
}
