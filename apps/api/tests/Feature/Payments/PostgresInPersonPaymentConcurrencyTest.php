<?php

namespace Tests\Feature\Payments;

use App\Contracts\Payments\InPersonPaymentGatewayFactory;
use App\Data\Payments\ProviderOrder;
use App\Data\Payments\ProviderOrderTransaction;
use App\Enums\MembershipRole;
use App\Enums\PaymentChannel;
use App\Enums\PaymentRequestPurpose;
use App\Enums\ReservationStatus;
use App\Models\IntegrationConnection;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentTerminal;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Services\Payments\ApplyMercadoPagoOrder;
use App\Services\Payments\InitiateInPersonPayment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenant;
use Tests\Fakes\FakeInPersonPaymentGateway;
use Tests\TestCase;
use Throwable;

class PostgresInPersonPaymentConcurrencyTest extends TestCase
{
    use CreatesTenant, DatabaseMigrations;

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql' && app()->environment('testing') && DB::getDatabaseName() === 'inn_test') {
            $tables = collect(DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename <> 'migrations'"))
                ->pluck('tablename')
                ->map(fn (string $table): string => '"'.str_replace('"', '""', $table).'"')
                ->all();
            if ($tables !== []) {
                DB::statement('TRUNCATE TABLE '.implode(', ', $tables).' RESTART IDENTITY CASCADE');
            }
        }
        putenv('COMMERCIAL_TEST_TEARDOWN=1');
        try {
            parent::tearDown();
        } finally {
            putenv('COMMERCIAL_TEST_TEARDOWN');
        }
    }

    public function test_two_requests_racing_for_one_terminal_have_one_winner(): void
    {
        $this->requirePostgresConcurrency();
        [$tenant, $property, $user, $membership] = $this->tenantEnvironment(MembershipRole::Finance);
        $connection = $this->connection($property->id);
        $terminal = $this->terminal($property->id, $connection);
        $first = $this->reservation($property->id);
        $second = $this->reservation($property->id);
        $this->app->instance(InPersonPaymentGatewayFactory::class, new FakeInPersonPaymentGateway);

        $results = $this->concurrently([
            fn (): string => app(InitiateInPersonPayment::class)->handle(
                $first, PaymentChannel::IntegratedTerminal, $terminal->id, PaymentRequestPurpose::FullOutstanding,
                null, null, $user->id, 'terminal-race-first',
            )->id,
            fn (): string => app(InitiateInPersonPayment::class)->handle(
                $second, PaymentChannel::IntegratedTerminal, $terminal->id, PaymentRequestPurpose::FullOutstanding,
                null, null, $user->id, 'terminal-race-second',
            )->id,
        ], $tenant, $membership);
        app(TenantContext::class)->set($tenant, $membership);

        $this->assertSame(1, collect($results)->where('ok', true)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(1, collect($results)->where('ok', false)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(1, PaymentAttempt::query()->where('payment_terminal_id', $terminal->id)->whereIn('state', $this->activeStates())->count());
    }

    public function test_two_terminals_racing_for_one_request_are_stopped_by_partial_unique_index(): void
    {
        $this->requirePostgresConcurrency();
        [$tenant, $property, $user, $membership] = $this->tenantEnvironment(MembershipRole::Finance);
        $connection = $this->connection($property->id);
        $firstTerminal = $this->terminal($property->id, $connection, 'NEWLAND_N950__SBX0000001');
        $secondTerminal = $this->terminal($property->id, $connection, 'NEWLAND_N950__SBX0000002');
        $reservation = $this->reservation($property->id);
        $this->app->instance(InPersonPaymentGatewayFactory::class, new FakeInPersonPaymentGateway);
        $template = app(InitiateInPersonPayment::class)->handle(
            $reservation, PaymentChannel::IntegratedTerminal, $firstTerminal->id, PaymentRequestPurpose::FullOutstanding,
            null, null, $user->id, 'request-race-template',
        );
        $template->update(['state' => 'cancelled']);
        $insert = function (string $terminalId, string $suffix) use ($template): string {
            $attempt = $template->replicate();
            $attempt->id = (string) Str::uuid();
            $attempt->payment_terminal_id = $terminalId;
            $attempt->state = 'creating';
            $attempt->external_reference = (string) Str::uuid();
            $attempt->idempotency_key = (string) Str::uuid();
            $attempt->provider_order_id = null;
            $attempt->provider_transaction_id = null;
            $attempt->save();

            return $suffix;
        };
        $results = $this->concurrently([
            fn (): string => $insert($firstTerminal->id, 'first'),
            fn (): string => $insert($secondTerminal->id, 'second'),
        ], $tenant, $membership);
        app(TenantContext::class)->set($tenant, $membership);

        $this->assertSame(1, collect($results)->where('ok', true)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(1, collect($results)->where('ok', false)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(1, PaymentAttempt::query()->where('payment_request_id', $template->payment_request_id)->whereIn('state', $this->activeStates())->count());
    }

    public function test_concurrent_authoritative_approval_posts_one_payment_folio_receipt_and_settlement(): void
    {
        $this->requirePostgresConcurrency();
        [$tenant, $property, $user, $membership] = $this->tenantEnvironment(MembershipRole::Finance);
        $connection = $this->connection($property->id);
        $terminal = $this->terminal($property->id, $connection);
        $reservation = $this->reservation($property->id);
        $fake = new FakeInPersonPaymentGateway;
        $this->app->instance(InPersonPaymentGatewayFactory::class, $fake);
        $attempt = app(InitiateInPersonPayment::class)->handle(
            $reservation, PaymentChannel::IntegratedTerminal, $terminal->id, PaymentRequestPurpose::FullOutstanding,
            null, null, $user->id, 'approval-race-template',
        );
        $created = $fake->fetchOrder($attempt->provider_order_id);
        $processed = new ProviderOrder(
            $created->id, 'point', $created->providerAccount, $created->externalReference,
            'processed', 'processed', $created->amountMinor, $created->currency,
            [new ProviderOrderTransaction($created->payments[0]->id, $created->amountMinor, 'processed', 'accredited', $created->amountMinor)],
            terminalId: $created->terminalId,
            applicationId: $created->applicationId, environment: $created->environment,
        );
        $results = $this->concurrently([
            fn (): string => app(ApplyMercadoPagoOrder::class)->handle($attempt, $processed)->state->value,
            fn (): string => app(ApplyMercadoPagoOrder::class)->handle($attempt, $processed)->state->value,
        ], $tenant, $membership);
        app(TenantContext::class)->set($tenant, $membership);

        $this->assertSame(2, collect($results)->where('ok', true)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(1, Payment::query()->where('provider_reference', $created->payments[0]->id)->count());
        $this->assertSame(1, DB::table('folio_lines')->where('payment_id', Payment::query()->sole()->id)->where('type', 'payment')->count());
        $this->assertSame(1, DB::table('settlement_entries')->where('source_type', 'payment')->where('source_id', $created->payments[0]->id)->count());
        $this->assertSame(1, DB::table('document_generation_requests')->where('payment_id', Payment::query()->sole()->id)->count());
    }

    /** @param array<int, callable(): string> $operations @return array<int, array{ok: bool, result?: string, error?: string}> */
    private function concurrently(array $operations, Tenant $tenant, Membership $membership): array
    {
        $directory = sys_get_temp_dir().'/inn-orders-race-'.Str::random(12);
        mkdir($directory, 0700, true);
        $barrier = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($barrier === false) {
            $this->fail('Unable to create the concurrency barrier.');
        }
        $children = [];
        foreach ($operations as $index => $operation) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('Unable to fork a PostgreSQL concurrency worker.');
            }
            if ($pid === 0) {
                fclose($barrier[0]);
                fread($barrier[1], 1);
                try {
                    DB::purge();
                    DB::reconnect();
                    app(TenantContext::class)->set($tenant, $membership);
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
            $this->assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, "Concurrency worker {$pid} failed.");
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

    private function connection(string $propertyId): IntegrationConnection
    {
        $connection = IntegrationConnection::query()->create([
            'name' => 'orders-pg-race', 'type' => 'payment', 'property_id' => $propertyId,
            'provider' => 'mercado_pago', 'product' => 'orders', 'external_account_id' => 'TEST-SELLER-ID',
            'provider_application_id' => 'TEST-APPLICATION-ID',
            'environment' => 'sandbox', 'status' => 'connected', 'is_enabled' => true,
            'configuration' => ['charge_currency' => 'ARS'], 'secret_reference' => 'env:MP_ORDERS_TEST_TOKEN',
        ]);
        $connection->connectionCapabilities()->create([
            'capability' => 'payment.point_orders', 'direction' => 'outbound', 'state' => 'enabled', 'configuration_version' => 1,
        ]);

        return $connection;
    }

    private function terminal(string $propertyId, IntegrationConnection $connection, string $providerId = 'NEWLAND_N950__SBX0000001'): PaymentTerminal
    {
        return PaymentTerminal::query()->create([
            'property_id' => $propertyId, 'integration_connection_id' => $connection->id,
            'provider' => 'mercado_pago', 'environment' => 'sandbox', 'provider_account' => 'TEST-SELLER-ID',
            'provider_terminal_id' => $providerId, 'display_name' => $providerId,
            'operating_mode' => 'PDV', 'is_enabled' => true, 'health_state' => 'healthy',
        ]);
    }

    private function reservation(string $propertyId): Reservation
    {
        return Reservation::factory()->create([
            'property_id' => $propertyId, 'status' => ReservationStatus::Confirmed, 'currency' => 'ARS',
            'subtotal_minor' => 10_000, 'tax_minor' => 0, 'total_minor' => 10_000,
        ]);
    }

    /** @return list<string> */
    private function activeStates(): array
    {
        return ['creating', 'checkout_ready', 'pending', 'queued', 'at_terminal', 'action_required', 'processing'];
    }

    private function requirePostgresConcurrency(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL Point/QR races are exercised by the PostgreSQL gate.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('The PostgreSQL Point/QR race gate requires pcntl.');
        }
    }
}
