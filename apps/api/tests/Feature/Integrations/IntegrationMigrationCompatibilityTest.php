<?php

namespace Tests\Feature\Integrations;

use App\Contracts\Integrations\SecretReferenceResolver;
use App\Models\IntegrationConnection;
use App\Services\Integrations\EndpointKeyService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class IntegrationMigrationCompatibilityTest extends TestCase
{
    use CreatesTenant, DatabaseMigrations;

    public function test_legacy_mercado_pago_identity_is_upgraded_without_plaintext_and_round_trips(): void
    {
        [$tenant, $property] = $this->tenantEnvironment();
        $path = database_path('migrations/2026_08_20_100001_create_integration_execution_kernel.php');
        $migration = require $path;
        $migration->down();

        $id = (string) Str::uuid();
        $rawEndpoint = str_repeat('w', 48);
        $configuration = [
            'provider' => 'mercado_pago', 'product' => 'checkout_pro', 'provider_account' => 'merchant-colombia',
            'environment' => 'sandbox', 'property_id' => $property->id, 'webhook_key' => $rawEndpoint,
        ];
        DB::table('integration_connections')->insert([
            'id' => $id, 'tenant_id' => $tenant->id, 'name' => 'Mercado Pago Colombia', 'type' => 'payment',
            'status' => 'configured', 'secret_reference' => 'env:MP_ACCESS_TOKEN', 'payment_webhook_key' => $rawEndpoint,
            'configuration' => json_encode($configuration, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration->up();
        $connection = IntegrationConnection::query()->findOrFail($id);
        $this->assertSame($property->id, $connection->property_id);
        $this->assertSame('mercado_pago', $connection->provider);
        $this->assertSame('checkout_pro', $connection->product);
        $this->assertSame('merchant-colombia', $connection->external_account_id);
        $this->assertSame('sandbox', $connection->environment);
        $this->assertSame(hash('sha256', $rawEndpoint), $connection->getRawOriginal('payment_webhook_key'));
        $this->assertNotSame($rawEndpoint, $connection->legacy_endpoint_key_ciphertext);
        $this->assertArrayNotHasKey('webhook_key', $connection->configuration);
        $this->assertSame($connection->id, app(EndpointKeyService::class)->resolveConnection($rawEndpoint)->id);
        $this->assertSame($rawEndpoint, app(EndpointKeyService::class)->rawForOutbound($connection));
        $this->assertDatabaseHas('integration_reconciliations', [
            'integration_connection_id' => $id,
            'kind' => 'legacy_endpoint_key_rotation',
            'reason_code' => 'raw_key_removed_from_database',
            'status' => 'open',
        ]);

        $migration->down();
        $this->assertDatabaseHas('integration_connections', ['id' => $id, 'name' => 'Mercado Pago Colombia', 'type' => 'payment']);
        $rolledBack = DB::table('integration_connections')->where('id', $id)->first();
        $rolledBackConfiguration = json_decode($rolledBack->configuration, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($rawEndpoint, $rolledBack->payment_webhook_key);
        $this->assertSame($rawEndpoint, $rolledBackConfiguration['webhook_key']);
        $this->assertSame($id, app(EndpointKeyService::class)->resolveConnection($rawEndpoint)->id);
        $migration->up();
        $connection = IntegrationConnection::query()->findOrFail($id);
        $this->assertSame(hash('sha256', $rawEndpoint), $connection->getRawOriginal('payment_webhook_key'));
        $this->assertSame($id, app(EndpointKeyService::class)->resolveConnection($rawEndpoint)->id);
        $this->assertDatabaseHas('integration_connections', ['id' => $id, 'provider' => 'mercado_pago', 'external_account_id' => 'merchant-colombia']);

        $connection->update(['configuration' => ['webhook_endpoint_key_reference' => 'vault://tenant/mp-endpoint']]);
        $this->app->bind(SecretReferenceResolver::class, fn () => new class($rawEndpoint) implements SecretReferenceResolver
        {
            public function __construct(private readonly string $raw) {}

            public function resolve(string $reference): string
            {
                return $this->raw;
            }
        });
        $this->assertSame($rawEndpoint, app(EndpointKeyService::class)->rawForOutbound($connection->fresh()));
        $this->assertNull($connection->fresh()->legacy_endpoint_key_ciphertext);
        $this->assertDatabaseHas('integration_reconciliations', [
            'integration_connection_id' => $id, 'kind' => 'legacy_endpoint_key_rotation', 'status' => 'resolved',
        ]);
    }

    public function test_duplicate_legacy_canonical_identities_are_disabled_and_reconciled_before_unique_index(): void
    {
        [$tenant, $property] = $this->tenantEnvironment();
        $migration = require database_path('migrations/2026_08_20_100001_create_integration_execution_kernel.php');
        $migration->down();
        $identity = [
            'provider' => 'mercado_pago', 'product' => 'checkout_pro', 'provider_account' => 'duplicate-account',
            'environment' => 'sandbox', 'property_id' => $property->id,
        ];
        foreach (['Duplicate A', 'Duplicate B'] as $name) {
            DB::table('integration_connections')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'name' => $name, 'type' => 'payment',
                'status' => 'configured', 'secret_reference' => 'env:DUPLICATE_MP',
                'configuration' => json_encode($identity, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $migration->up();

        $this->assertSame(2, IntegrationConnection::query()->where('provider', 'mercado_pago')->count());
        $this->assertSame(1, IntegrationConnection::query()->where('external_account_id', 'duplicate-account')->count());
        $conflict = IntegrationConnection::query()->where('external_account_id', 'like', 'conflict:%')->sole();
        $this->assertFalse($conflict->is_enabled);
        $this->assertDatabaseHas('integration_reconciliations', [
            'integration_connection_id' => $conflict->id, 'kind' => 'canonical_identity_collision',
            'reason_code' => 'duplicate_legacy_connection_identity', 'status' => 'open',
        ]);
    }

    public function test_global_connection_and_cursor_uniqueness_is_null_safe_on_sqlite(): void
    {
        $this->tenantEnvironment();
        $base = [
            'name' => 'Global one', 'type' => 'webhook', 'provider' => 'contract_fake', 'product' => 'events',
            'external_account_id' => 'global-account', 'environment' => 'sandbox', 'status' => 'configured',
            'secret_reference' => 'env:CONTRACT_FAKE', 'configuration' => [], 'capabilities' => ['webhook.outbound'],
        ];
        $connection = IntegrationConnection::query()->create($base);
        try {
            IntegrationConnection::query()->create($base + ['name' => 'Global duplicate']);
            $this->fail('Duplicate global connection identity must be rejected.');
        } catch (UniqueConstraintViolationException) {
        }

        DB::table('integration_sync_cursors')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $connection->tenant_id, 'integration_connection_id' => $connection->id,
            'property_scope_key' => '00000000-0000-0000-0000-000000000000', 'capability' => 'webhook.outbound', 'direction' => 'outbound',
            'version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('integration_sync_cursors')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $connection->tenant_id, 'integration_connection_id' => $connection->id,
            'property_scope_key' => '00000000-0000-0000-0000-000000000000', 'capability' => 'webhook.outbound', 'direction' => 'outbound',
            'version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
