<?php

namespace Tests\Feature;

use App\Exceptions\IntegrationConnectionException;
use App\Services\IntegrationConnectionService;
use App\Services\Integrations\IntegrationConnectionHealthService;
use App\Services\Integrations\IntegrationSecretResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class MewsIntegrationTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_mews_health_check_retries_rate_limits_and_persists_only_safe_remote_metadata(): void
    {
        $this->tenantEnvironment(authenticate: false);
        $this->fakeSecrets();
        Http::fakeSequence()
            ->push(['Message' => 'Slow down'], 429, ['Retry-After' => '0'])
            ->push([
                'Enterprise' => [
                    'Id' => 'enterprise-123',
                    'Name' => 'Mews Demo Lodge',
                    'TimeZoneIdentifier' => 'America/Bogota',
                ],
            ]);
        $connection = app(IntegrationConnectionService::class)->configure(
            'Mews Connector',
            'mews',
            ['environment' => 'demo'],
            'env://MEWS_TEST_CREDENTIALS',
        );

        $checked = app(IntegrationConnectionHealthService::class)->test($connection);

        $this->assertSame('connected', $checked->status);
        $this->assertSame('Mews Demo Lodge', data_get($checked->configuration, 'remote.enterprise_name'));
        $this->assertNotNull($checked->last_checked_at);
        $this->assertNull($checked->last_error);
        $this->assertStringNotContainsString('client-secret-token', json_encode($checked->configuration, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('access-secret-token', json_encode($checked->configuration, JSON_THROW_ON_ERROR));
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.mews-demo.com/api/connector/v1/configuration/get'
            && $request['ClientToken'] === 'client-secret-token'
            && $request['AccessToken'] === 'access-secret-token');
    }

    public function test_mews_authentication_failure_records_a_safe_actionable_error(): void
    {
        $this->tenantEnvironment(authenticate: false);
        $this->fakeSecrets();
        Http::fake(['api.mews-demo.com/*' => Http::response(['Message' => 'Rejected token access-secret-token'], 401)]);
        $connection = app(IntegrationConnectionService::class)->configure(
            'Mews Connector',
            'mews',
            ['environment' => 'demo'],
            'env://MEWS_TEST_CREDENTIALS',
        );

        try {
            app(IntegrationConnectionHealthService::class)->test($connection);
            $this->fail('Invalid Mews credentials must fail the health check.');
        } catch (IntegrationConnectionException $exception) {
            $this->assertSame('Mews rejected the configured credentials or permissions.', $exception->getMessage());
        }

        $failed = $connection->fresh();
        $this->assertSame('error', $failed->status);
        $this->assertSame('Mews rejected the configured credentials or permissions.', $failed->last_error);
        $this->assertStringNotContainsString('access-secret-token', $failed->last_error);
    }

    public function test_admin_can_run_the_idempotent_health_endpoint_without_exposing_the_secret_reference(): void
    {
        [$tenant] = $this->tenantEnvironment();
        $this->fakeSecrets();
        Http::fake(['api.mews-demo.com/*' => Http::response([
            'Enterprise' => [
                'Id' => 'enterprise-123',
                'Name' => 'Mews Demo Lodge',
                'TimeZoneIdentifier' => 'America/Bogota',
            ],
        ])]);
        $connection = app(IntegrationConnectionService::class)->configure(
            'Mews Connector',
            'mews',
            ['environment' => 'demo'],
            'env://MEWS_TEST_CREDENTIALS',
        );

        $response = $this->withHeaders([
            'X-Tenant-ID' => $tenant->id,
            'Idempotency-Key' => 'mews-health-check-0001',
        ])->postJson("/api/v1/integrations/{$connection->id}/test");

        $response->assertOk()
            ->assertJsonPath('data.status', 'connected')
            ->assertJsonPath('data.configuration.remote.enterprise_name', 'Mews Demo Lodge')
            ->assertJsonMissing(['secret_reference' => 'env://MEWS_TEST_CREDENTIALS']);
        $this->assertStringNotContainsString('secret_reference', $response->getContent());
        Http::assertSentCount(1);
    }

    private function fakeSecrets(): void
    {
        $this->app->bind(IntegrationSecretResolver::class, fn (): IntegrationSecretResolver => new class extends IntegrationSecretResolver
        {
            public function resolve(?string $reference): array
            {
                return [
                    'client_token' => 'client-secret-token',
                    'access_token' => 'access-secret-token',
                ];
            }
        });
    }
}
