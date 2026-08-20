<?php

namespace Tests\Feature\Integrations;

use App\Data\Integrations\IntegrationHttpResult;
use App\Enums\MembershipRole;
use App\Events\IntegrationTransportMeasured;
use App\Exceptions\Integrations\AmbiguousRemoteResultException;
use App\Models\IntegrationConnection;
use App\Models\Property;
use App\Services\IntegrationConnectionService;
use App\Services\Integrations\IntegrationHttpClient;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class IntegrationTransportAndAuthorizationTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_transport_retries_429_and_5xx_emits_safe_metrics_and_uses_stable_remote_idempotency(): void
    {
        [, $property] = $this->tenantEnvironment();
        $connection = $this->connection($property->id);
        Event::fake([IntegrationTransportMeasured::class]);
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push(['error' => 'slow down'], 429, ['Retry-After' => '1'])
            ->push(['error' => 'temporary'], 503)
            ->push(['accepted' => true], 202);

        $result = app(IntegrationHttpClient::class)->request(
            $connection, 'POST', 'https://contract-fake.invalid/events', ['event_id' => 'evt-1'], [], 'remote-command-0001',
        );

        $this->assertSame(202, $result->status);
        $this->assertSame(3, $result->attempts);
        Http::assertSentCount(3);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Idempotency-Key', 'remote-command-0001'));
        Event::assertDispatched(IntegrationTransportMeasured::class, 3);
        $this->assertNull($connection->fresh()->throttled_until);
        $this->assertSame('healthy', $connection->fresh()->health_status);
    }

    public function test_mutation_requires_idempotency_and_timeout_can_recover_authoritatively(): void
    {
        [, $property] = $this->tenantEnvironment();
        $connection = $this->connection($property->id);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::failedConnection('socket closed after provider commit'));

        try {
            app(IntegrationHttpClient::class)->request($connection, 'POST', 'https://contract-fake.invalid/events');
            $this->fail('A remote mutation without an idempotency key must fail before transport.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('idempotency', $exception->getMessage());
        }

        $recovered = new IntegrationHttpResult(200, ['id' => 'remote-1'], hash('sha256', 'request'), hash('sha256', 'response'), 20, 1);
        $result = app(IntegrationHttpClient::class)->request(
            $connection, 'POST', 'https://contract-fake.invalid/events', ['event_id' => 'evt-1'], [], 'remote-command-0002', fn () => $recovered,
        );
        $this->assertSame($recovered, $result);
    }

    public function test_timeout_without_authoritative_recovery_is_ambiguous_and_circuit_eventually_opens(): void
    {
        [, $property] = $this->tenantEnvironment();
        $connection = $this->connection($property->id);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::failedConnection('timeout token=must-not-leak'));

        for ($failure = 1; $failure <= 5; $failure++) {
            try {
                app(IntegrationHttpClient::class)->request(
                    $connection->fresh(), 'POST', 'https://contract-fake.invalid/events', ['event_id' => 'evt-'.$failure], [], 'remote-command-'.$failure,
                );
                $this->fail('An unrecovered timeout must remain ambiguous.');
            } catch (AmbiguousRemoteResultException $exception) {
                $this->assertStringNotContainsString('must-not-leak', $connection->fresh()->last_error ?? '');
            }
        }
        $this->assertTrue($connection->fresh()->circuit_opened_at->isFuture());
    }

    public function test_property_scoped_role_cannot_view_or_mutate_another_property_connection(): void
    {
        [$tenant, $property] = $this->tenantEnvironment(MembershipRole::Operations);
        $membership = app(TenantContext::class)->membership();
        $other = Property::factory()->create();
        app(TenantContext::class)->set($tenant);
        $connection = IntegrationConnection::query()->create([
            'property_id' => $other->id,
            'name' => 'Other property', 'type' => 'webhook', 'provider' => 'contract_fake', 'product' => 'webhooks',
            'external_account_id' => 'other-account', 'environment' => 'sandbox', 'status' => 'configured',
            'secret_reference' => 'env:CONTRACT_FAKE', 'configuration' => [], 'capabilities' => ['webhook.outbound'],
        ]);
        app(TenantContext::class)->set($tenant, $membership);

        $headers = ['X-Tenant-ID' => $tenant->id, 'Idempotency-Key' => 'scope-test-command-1'];
        $this->getJson('/api/v1/integrations/'.$connection->id, $headers)->assertForbidden();
        $this->postJson('/api/v1/integrations/'.$connection->id.'/state', ['action' => 'disable', 'reason' => 'Scope test'], $headers)->assertForbidden();
        $this->getJson('/api/v1/integrations', $headers)->assertForbidden();
    }

    public function test_integration_command_api_requires_stable_idempotency_header(): void
    {
        [$tenant, $property] = $this->tenantEnvironment();
        $connection = $this->connection($property->id);

        $this->postJson('/api/v1/integrations/'.$connection->id.'/state', ['action' => 'disable', 'reason' => 'Maintenance'], ['X-Tenant-ID' => $tenant->id])
            ->assertUnprocessable()->assertJsonValidationErrors('key');
    }

    private function connection(string $propertyId): IntegrationConnection
    {
        $connection = app(IntegrationConnectionService::class)->configure(
            'Transport fake', 'webhook', [], 'env:CONTRACT_FAKE', $propertyId,
            'contract_fake', 'webhooks', 'account-transport', 'sandbox', ['webhook.outbound'],
        );

        return app(IntegrationConnectionService::class)->enable($connection, auth()->id(), 'Enable transport fake');
    }
}
