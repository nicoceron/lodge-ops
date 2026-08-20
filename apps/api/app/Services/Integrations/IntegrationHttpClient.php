<?php

namespace App\Services\Integrations;

use App\Data\Integrations\IntegrationHttpResult;
use App\Events\IntegrationTransportMeasured;
use App\Exceptions\Integrations\AmbiguousRemoteResultException;
use App\Exceptions\Integrations\RateLimitedIntegrationException;
use App\Exceptions\Integrations\RetryableIntegrationException;
use App\Models\IntegrationConnection;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class IntegrationHttpClient
{
    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,string>  $headers
     * @param  null|callable():?IntegrationHttpResult  $recoverAfterAmbiguous
     */
    public function request(
        IntegrationConnection $connection,
        string $method,
        string $url,
        array $payload = [],
        array $headers = [],
        ?string $idempotencyKey = null,
        ?callable $recoverAfterAmbiguous = null,
    ): IntegrationHttpResult {
        $method = strtoupper($method);
        $mutation = ! in_array($method, ['GET', 'HEAD'], true);
        if ($mutation && ($idempotencyKey === null || trim($idempotencyKey) === '')) {
            throw new RuntimeException('Remote mutations require a stable idempotency key.');
        }
        if ($connection->circuit_opened_at?->isFuture()) {
            throw new RetryableIntegrationException('The integration circuit is open.');
        }
        if ($connection->throttled_until?->isFuture()) {
            throw new RateLimitedIntegrationException('The provider throttle window is active.', (int) now()->diffInSeconds($connection->throttled_until));
        }
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $requestChecksum = hash('sha256', $method."\0".$url."\0".$encoded);
        $attempt = 0;
        $maxAttempts = 3;
        $started = hrtime(true);
        while (++$attempt <= $maxAttempts) {
            try {
                $pending = Http::acceptJson()->asJson()->connectTimeout(5)->timeout(20)->withHeaders($headers);
                if ($idempotencyKey !== null) {
                    $pending = $pending->withHeader('Idempotency-Key', $idempotencyKey);
                }
                $response = match ($method) {
                    'GET' => $pending->get($url, $payload),
                    'HEAD' => $pending->head($url),
                    'POST' => $pending->withBody($encoded, 'application/json')->post($url),
                    'PUT' => $pending->withBody($encoded, 'application/json')->put($url),
                    'PATCH' => $pending->withBody($encoded, 'application/json')->patch($url),
                    'DELETE' => $pending->withBody($encoded, 'application/json')->delete($url),
                    default => throw new RuntimeException('Unsupported integration HTTP method.'),
                };
                if ($response->status() === 429) {
                    $this->emit($connection, 'rate_limited', 429, $started, $attempt, $requestChecksum, hash('sha256', $response->body()));
                    $retryAfter = $this->retryAfterSeconds($response->header('Retry-After'));
                    $connection->update(['throttled_until' => now()->addSeconds($retryAfter), 'health_status' => 'degraded', 'last_error_at' => now(), 'last_error' => 'Provider rate limit received.']);
                    if ($attempt >= $maxAttempts) {
                        throw new RateLimitedIntegrationException('The provider rate limit remains active.', $retryAfter);
                    }
                    $this->wait($retryAfter);

                    continue;
                }
                if ($response->serverError()) {
                    $this->emit($connection, 'server_error', $response->status(), $started, $attempt, $requestChecksum, hash('sha256', $response->body()));
                    if ($attempt < $maxAttempts) {
                        $this->wait($attempt);

                        continue;
                    }
                    $this->recordFailure($connection, 'Provider server error.');
                    throw new RetryableIntegrationException('The provider returned a retryable server error.');
                }
                if (! $response->successful()) {
                    $this->emit($connection, 'rejected', $response->status(), $started, $attempt, $requestChecksum, hash('sha256', $response->body()));
                    $this->recordFailure($connection, 'Provider request rejected.');
                    throw new RuntimeException('The provider rejected the request with HTTP '.$response->status().'.');
                }
                $json = $response->body() === '' ? null : $response->json();
                if ($json !== null && ! is_array($json)) {
                    throw new RuntimeException('The provider returned malformed JSON.');
                }
                $connection->update(['circuit_failure_count' => 0, 'circuit_opened_at' => null, 'throttled_until' => null, 'last_success_at' => now(), 'health_status' => 'healthy', 'last_error' => null]);
                $this->emit($connection, 'success', $response->status(), $started, $attempt, $requestChecksum, hash('sha256', $response->body()));

                return new IntegrationHttpResult(
                    $response->status(), $json, $requestChecksum, hash('sha256', $response->body()),
                    (int) ((hrtime(true) - $started) / 1_000_000), $attempt,
                );
            } catch (ConnectionException $exception) {
                $this->emit($connection, 'connection_error', null, $started, $attempt, $requestChecksum);
                if ($mutation && $recoverAfterAmbiguous !== null) {
                    $recovered = $recoverAfterAmbiguous();
                    if ($recovered instanceof IntegrationHttpResult) {
                        return $recovered;
                    }
                }
                if ($attempt < $maxAttempts) {
                    $this->wait($attempt);

                    continue;
                }
                $this->recordFailure($connection, 'Provider connection timed out.');
                throw new AmbiguousRemoteResultException('The provider timed out; authoritative recovery is required.', previous: $exception);
            } catch (RateLimitedIntegrationException|RetryableIntegrationException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                $this->recordFailure($connection, SafeIntegrationError::from($exception));
                throw $exception;
            }
        }
        throw new RetryableIntegrationException('The provider request exhausted its retry policy.');
    }

    private function retryAfterSeconds(?string $value): int
    {
        if ($value !== null && ctype_digit(trim($value))) {
            return max(1, min(3600, (int) $value));
        }
        if ($value !== null) {
            try {
                return max(1, min(3600, (int) now()->diffInSeconds(CarbonImmutable::parse($value), false)));
            } catch (Throwable) {
            }
        }

        return 60;
    }

    private function recordFailure(IntegrationConnection $connection, string $error): void
    {
        $failures = $connection->circuit_failure_count + 1;
        $connection->update([
            'circuit_failure_count' => $failures,
            'circuit_opened_at' => $failures >= 5 ? now()->addMinutes(5) : null,
            'health_status' => 'degraded',
            'last_error_at' => now(),
            'last_error' => SafeIntegrationError::from($error),
        ]);
    }

    private function wait(int $seconds): void
    {
        if (! app()->runningUnitTests()) {
            usleep(min(5, max(1, $seconds)) * 1_000_000);
        }
    }

    private function emit(IntegrationConnection $connection, string $outcome, ?int $status, int $started, int $attempt, string $requestChecksum, ?string $responseChecksum = null): void
    {
        event(new IntegrationTransportMeasured(
            $connection->tenant_id,
            $connection->id,
            $outcome,
            $status,
            (int) ((hrtime(true) - $started) / 1_000_000),
            $attempt,
            $requestChecksum,
            $responseChecksum,
        ));
    }
}
