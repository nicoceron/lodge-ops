<?php

namespace Tests\Feature\Integrations;

use App\Contracts\Integrations\ReservationsImportPort;
use App\Data\Integrations\IntegrationHealthResult;
use App\Data\Integrations\IntegrationHttpResult;
use App\Data\Integrations\IntegrationItemResult;
use App\Data\Integrations\IntegrationPage;
use App\Data\Integrations\IntegrationServiceIdentity;
use App\Enums\MembershipRole;
use App\Events\IntegrationTransportMeasured;
use App\Exceptions\Integrations\AmbiguousRemoteResultException;
use App\Exceptions\Integrations\RateLimitedIntegrationException;
use App\Jobs\ExecuteIntegrationRunJob;
use App\Models\IntegrationConnection;
use App\Models\Property;
use App\Services\IntegrationConnectionService;
use App\Services\Integrations\CapabilityPortRegistry;
use App\Services\Integrations\IntegrationHttpClient;
use App\Services\Integrations\IntegrationRunService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class IntegrationTransportAndAuthorizationTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_transport_persists_429_without_in_window_retry_then_retries_5xx_with_stable_remote_idempotency(): void
    {
        [, $property] = $this->tenantEnvironment();
        $connection = $this->connection($property->id);
        Event::fake([IntegrationTransportMeasured::class]);
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push(['error' => 'slow down'], 429, ['Retry-After' => '1'])
            ->push(['error' => 'temporary'], 503)
            ->push(['accepted' => true], 202);

        try {
            app(IntegrationHttpClient::class)->request(
                $connection, 'POST', 'https://contract-fake.invalid/events', ['event_id' => 'evt-1'], [], 'remote-command-0001',
            );
            $this->fail('A 429 must release to the persisted provider throttle instead of retrying in-window.');
        } catch (RateLimitedIntegrationException $exception) {
            $this->assertSame(1, $exception->retryAfterSeconds);
        }
        $this->assertTrue($connection->fresh()->throttled_until->isFuture());
        try {
            app(IntegrationHttpClient::class)->request(
                $connection->fresh(), 'POST', 'https://contract-fake.invalid/events', ['event_id' => 'evt-1'], [], 'remote-command-0001',
            );
            $this->fail('The persisted throttle window must prevent a second HTTP request.');
        } catch (RateLimitedIntegrationException) {
            $this->addToAssertionCount(1);
        }
        Http::assertSentCount(1);
        $this->travel(2)->seconds();
        $result = app(IntegrationHttpClient::class)->request(
            $connection->fresh(), 'POST', 'https://contract-fake.invalid/events', ['event_id' => 'evt-1'], [], 'remote-command-0001',
        );

        $this->assertSame(202, $result->status);
        $this->assertSame(2, $result->attempts);
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

    public function test_http_date_retry_after_persists_the_exact_throttle_window_without_an_in_window_request(): void
    {
        [, $property] = $this->tenantEnvironment();
        $connection = $this->connection($property->id);
        $this->travelTo(now()->startOfSecond());
        Http::preventStrayRequests();
        Http::fake([
            '*' => Http::response(['error' => 'slow down'], 429, ['Retry-After' => now()->addSeconds(90)->toRfc7231String()]),
        ]);

        try {
            app(IntegrationHttpClient::class)->request(
                $connection, 'POST', 'https://contract-fake.invalid/events', ['event_id' => 'evt-date'], [], 'remote-command-date-0001',
            );
            $this->fail('An HTTP-date Retry-After must release to the persisted throttle window.');
        } catch (RateLimitedIntegrationException $exception) {
            $this->assertSame(90, $exception->retryAfterSeconds);
        }
        $this->assertSame(90, (int) now()->diffInSeconds($connection->fresh()->throttled_until));

        try {
            app(IntegrationHttpClient::class)->request(
                $connection->fresh(), 'POST', 'https://contract-fake.invalid/events', ['event_id' => 'evt-date'], [], 'remote-command-date-0001',
            );
            $this->fail('The HTTP-date throttle window must prevent another provider request.');
        } catch (RateLimitedIntegrationException $exception) {
            $this->assertSame(90, $exception->retryAfterSeconds);
        }
        Http::assertSentCount(1);
    }

    public function test_rate_limited_run_job_releases_for_the_provider_delay_without_refetching_in_window(): void
    {
        Queue::fake();
        [$tenant, $property, $user] = $this->tenantEnvironment();
        $connection = app(IntegrationConnectionService::class)->configure(
            'Rate-limit run fake', 'webhook', [], 'env:CONTRACT_FAKE', $property->id,
            'contract_fake', 'reservations', 'rate-limit-account', 'sandbox', ['reservations.import'],
        );
        $connection = app(IntegrationConnectionService::class)->enable($connection, $user->id, 'Enable rate-limit run fake.');
        app(CapabilityPortRegistry::class)->register(
            'contract_fake', 'reservations', 'reservations.import', new RateLimitedReservationPort,
        );
        $run = app(IntegrationRunService::class)->start(
            $connection, 'reservations.import', $property->id, 'manual', 'rate-limit-run-job-0001', $user->id,
        );
        $job = (new ExecuteIntegrationRunJob($tenant->id, $run->id))->withFakeQueueInteractions();

        $job->handle(app(IntegrationRunService::class), app(TenantContext::class));

        $job->assertReleased(42);
        $this->assertSame('queued', $run->fresh()->status);
        $this->assertSame(1, $run->fresh()->attempt);
        $this->assertSame(0, $run->items()->count());
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

final class RateLimitedReservationPort implements ReservationsImportPort
{
    public function test(IntegrationConnection $connection): IntegrationHealthResult
    {
        return new IntegrationHealthResult(false, 1, null, 'Rate-limited test port.');
    }

    public function fetchPage(IntegrationConnection $connection, ?array $checkpoint): IntegrationPage
    {
        throw new RateLimitedIntegrationException('Provider throttle fixture.', 42);
    }

    public function importReservation(IntegrationConnection $connection, string $externalKey, IntegrationServiceIdentity $identity, string $idempotencyKey): IntegrationItemResult
    {
        throw new \LogicException('The rate-limited page must not produce an item.');
    }
}
