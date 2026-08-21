<?php

namespace Tests\Feature\Integrations;

use App\Models\IntegrationConnection;
use App\Models\IntegrationMapping;
use App\Models\IntegrationReconciliation;
use App\Services\IntegrationConnectionService;
use App\Services\Integrations\IntegrationMappingService;
use App\Services\Integrations\IntegrationReconciliationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class IntegrationOperatorInputAdmissionTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_mapping_service_and_models_reject_recursive_credentials_headers_and_card_data_before_persistence(): void
    {
        [, $property] = $this->tenantEnvironment();
        $connection = $this->connection($property->id);
        $vectors = [
            'plain-secret-key' => ['nested' => ['api_key' => 'raw-api-key-value']],
            'authorization-header' => ['headers' => ['Authorization' => 'Bearer raw-bearer-value']],
            'bearer-text' => ['note' => 'Use Bearer raw-token-value for the request'],
            'basic-text' => ['note' => 'Basic dXNlcjpwYXNzd29yZA=='],
            'credential-url' => ['url' => 'https://operator:password@example.test/callback'],
            'provider-token' => ['note' => 'ghp_abcdefghijklmnopqrstuvwxyz123456'],
            'pan' => ['note' => '4111 1111 1111 1111'],
            'bad-reference' => ['signing_secret_reference' => 'plaintext-secret-value'],
        ];

        foreach ($vectors as $name => $safeFacts) {
            try {
                app(IntegrationMappingService::class)->version(
                    $connection, $property->id, 'reservations.import', 'inbound', 'reservation',
                    'local-'.$name, 'booking', 'external-'.$name, 1, $safeFacts,
                );
                $this->fail("{$name} must be rejected before mapping persistence.");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, IntegrationMapping::query()->count());

        $this->expectException(ValidationException::class);
        IntegrationMapping::query()->create([
            'integration_connection_id' => $connection->id, 'property_id' => $property->id,
            'capability' => 'reservations.import', 'direction' => 'inbound',
            'local_entity_type' => 'reservation', 'local_key' => 'direct-local',
            'external_entity_type' => 'booking', 'external_key' => 'direct-external',
            'transform_version' => 1, 'valid_from' => now(), 'safe_facts' => ['password' => 'direct-model-secret'],
        ]);
    }

    public function test_approved_nested_secret_reference_is_preserved_without_resolving_it(): void
    {
        [, $property] = $this->tenantEnvironment();
        $mapping = app(IntegrationMappingService::class)->version(
            $this->connection($property->id), $property->id, 'reservations.import', 'inbound',
            'reservation', 'local-approved', 'booking', 'external-approved', 1,
            ['provider' => ['signing_secret_reference' => 'vault://tenant/integrations/signing'], 'state' => 'confirmed'],
        );

        $this->assertSame('vault://tenant/integrations/signing', data_get($mapping->safe_facts, 'provider.signing_secret_reference'));
        $this->assertSame(64, strlen($mapping->facts_checksum));
    }

    public function test_reconciliation_model_and_service_reject_sensitive_facts_and_resolution_before_audit_or_jobs(): void
    {
        Queue::fake();
        [, $property, $user] = $this->tenantEnvironment();
        $connection = $this->connection($property->id);

        try {
            IntegrationReconciliation::query()->create([
                'integration_connection_id' => $connection->id, 'property_id' => $property->id,
                'kind' => 'manual', 'status' => 'open', 'reason_code' => 'operator',
                'safe_facts' => ['cookie' => 'session=raw-cookie-value'],
            ]);
            $this->fail('Sensitive reconciliation facts must be rejected.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        $reconciliation = IntegrationReconciliation::query()->create([
            'integration_connection_id' => $connection->id, 'property_id' => $property->id,
            'kind' => 'manual', 'status' => 'open', 'reason_code' => 'operator', 'safe_facts' => ['source' => 'operator'],
        ]);
        try {
            app(IntegrationReconciliationService::class)->resolve($reconciliation, $user->id, 'password=raw-resolution-value');
            $this->fail('Sensitive reconciliation resolution must be rejected.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        try {
            app(IntegrationReconciliationService::class)->reconcile($connection, $user->id, 'token=raw-reconciliation-reason');
            $this->fail('Sensitive reconciliation reasons must be rejected.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame('open', $reconciliation->fresh()->status);
        $this->assertNull($reconciliation->fresh()->resolution);
        $this->assertSame(0, DB::table('integration_operations')->count());
        Queue::assertNothingPushed();
        $this->assertStringNotContainsString('raw-', $this->integrationSinkDump());
    }

    public function test_mapping_and_reconciliation_apis_reject_sensitive_operator_input_and_leave_no_replay_receipt(): void
    {
        [$tenant, $property, , $membership] = $this->tenantEnvironment();
        $connection = $this->connection($property->id);
        $mappingPayload = [
            'property_id' => $property->id, 'capability' => 'reservations.import', 'direction' => 'inbound',
            'local_entity_type' => 'reservation', 'local_key' => 'api-local',
            'external_entity_type' => 'booking', 'external_key' => 'api-external',
            'transform_version' => 1, 'safe_facts' => ['token' => 'raw-api-token'],
        ];
        $this->postJson('/api/v1/integrations/'.$connection->id.'/mappings', $mappingPayload, [
            'X-Tenant-ID' => $tenant->id, 'Idempotency-Key' => 'mapping-admission-0001',
        ])->assertUnprocessable();
        $this->assertDatabaseMissing('integration_mappings', ['external_key' => 'api-external']);

        app(TenantContext::class)->set($tenant, $membership);
        $reconciliation = IntegrationReconciliation::query()->create([
            'integration_connection_id' => $connection->id, 'property_id' => $property->id,
            'kind' => 'manual', 'status' => 'open', 'reason_code' => 'operator', 'safe_facts' => ['source' => 'api'],
        ]);
        $this->postJson('/api/v1/integration-reconciliations/'.$reconciliation->id.'/resolve', [
            'resolution' => 'Authorization: Bearer raw-api-resolution',
        ], ['X-Tenant-ID' => $tenant->id, 'Idempotency-Key' => str_repeat('r', 24)])->assertUnprocessable();
        $this->postJson('/api/v1/integrations/'.$connection->id.'/reconcile', [
            'reason' => 'password=raw-api-reconciliation-reason',
        ], ['X-Tenant-ID' => $tenant->id, 'Idempotency-Key' => str_repeat('s', 24)])->assertUnprocessable();
        $this->assertSame('open', $reconciliation->fresh()->status);
        $this->assertSame(0, DB::table('idempotency_keys')->count());
        $this->assertStringNotContainsString('raw-', $this->integrationSinkDump());
    }

    private function connection(string $propertyId): IntegrationConnection
    {
        return app(IntegrationConnectionService::class)->configure(
            'Operator input guard', 'webhook', [], 'env:OPERATOR_INPUT_GUARD', $propertyId,
            'contract_fake', 'reservations', 'operator-input-account', 'sandbox', ['reservations.import'],
        );
    }

    private function integrationSinkDump(): string
    {
        return collect(['integration_mappings', 'integration_reconciliations', 'integration_operations', 'idempotency_keys', 'jobs'])
            ->map(fn (string $table): string => DB::table($table)->get()->toJson())
            ->implode('\n');
    }
}
