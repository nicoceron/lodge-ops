<?php

namespace App\Services\Integrations;

use App\Exceptions\IntegrationConnectionException;
use App\Models\IntegrationConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class MewsConnectorClient
{
    public function __construct(private IntegrationSecretResolver $secrets) {}

    /** @return array{enterprise_id: string|null, enterprise_name: string, timezone: string|null, environment: string} */
    public function configuration(IntegrationConnection $connection): array
    {
        if ($connection->type !== 'mews') {
            throw new IntegrationConnectionException('The Mews client can only test Mews connections.');
        }

        $environment = data_get($connection->configuration, 'environment', 'demo');
        $baseUrl = match ($environment) {
            'demo' => 'https://api.mews-demo.com',
            'production' => 'https://api.mews.com',
            default => throw new IntegrationConnectionException('Mews environment must be demo or production.'),
        };
        $secret = $this->secrets->resolve($connection->secret_reference);
        $clientToken = $this->requiredToken($secret, 'client_token');
        $accessToken = $this->requiredToken($secret, 'access_token');
        $payload = [
            'ClientToken' => $clientToken,
            'AccessToken' => $accessToken,
            'Client' => (string) config('services.mews.client', 'LodgeOps Connector 1.0'),
        ];
        if (filled(data_get($connection->configuration, 'enterprise_id'))) {
            $payload['EnterpriseId'] = data_get($connection->configuration, 'enterprise_id');
        }

        $response = $this->postWithBackoff("{$baseUrl}/api/connector/v1/configuration/get", $payload);
        $enterprise = $response->json('Enterprise');
        if (! is_array($enterprise) || blank($enterprise['Name'] ?? null)) {
            throw new IntegrationConnectionException('Mews returned an incomplete enterprise configuration.');
        }

        return [
            'enterprise_id' => is_string($enterprise['Id'] ?? null) ? $enterprise['Id'] : null,
            'enterprise_name' => (string) $enterprise['Name'],
            'timezone' => is_string($enterprise['TimeZoneIdentifier'] ?? null) ? $enterprise['TimeZoneIdentifier'] : null,
            'environment' => $environment,
        ];
    }

    /** @param array<string, mixed> $secret */
    private function requiredToken(array $secret, string $key): string
    {
        $value = $secret[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new IntegrationConnectionException("The Mews secret is missing {$key}.");
        }

        return trim($value);
    }

    /** @param array<string, mixed> $payload */
    private function postWithBackoff(string $url, array $payload): Response
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $response = Http::acceptJson()
                    ->asJson()
                    ->connectTimeout(5)
                    ->timeout(15)
                    ->post($url, $payload);
            } catch (ConnectionException) {
                if ($attempt === 3) {
                    throw new IntegrationConnectionException('Mews could not be reached. Check network access and try again.');
                }
                usleep($attempt * 200_000);

                continue;
            }

            if ($response->status() === 429 || $response->serverError()) {
                if ($attempt === 3) {
                    throw new IntegrationConnectionException($response->status() === 429
                        ? 'Mews rate-limited the connection test. Wait briefly and try again.'
                        : 'Mews is temporarily unavailable. Try again shortly.');
                }
                $retryAfter = min(2, max(0, (int) $response->header('Retry-After')));
                usleep(max($attempt * 200_000, $retryAfter * 1_000_000));

                continue;
            }

            if ($response->status() === 401 || $response->status() === 403) {
                throw new IntegrationConnectionException('Mews rejected the configured credentials or permissions.');
            }
            if ($response->failed()) {
                throw new IntegrationConnectionException("Mews rejected the connection test with HTTP {$response->status()}.");
            }

            return $response;
        }

        throw new IntegrationConnectionException('Mews could not be reached.');
    }
}
