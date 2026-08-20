<?php

namespace App\Services\DirectBooking;

use App\Contracts\DirectBooking\BotVerifier;
use App\Data\DirectBooking\BotVerificationResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Throwable;

final class CloudflareTurnstileVerifier implements BotVerifier
{
    public function configurationReady(): bool
    {
        return $this->secret() !== '' && $this->allowedHostnames() !== [];
    }

    public function verify(string $responseToken, ?string $remoteIp, string $expectedAction, string $idempotencyKey): BotVerificationResult
    {
        $secret = $this->secret();
        $allowedHostnames = $this->allowedHostnames();
        if ($secret === '' || $allowedHostnames === []) {
            return new BotVerificationResult(false, null, null, ['missing-configuration']);
        }
        if ($responseToken === '' || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $idempotencyKey) !== 1) {
            return new BotVerificationResult(false, null, null, ['invalid-input']);
        }

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout((int) config('direct-booking.turnstile_timeout_seconds', 5))
                ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', array_filter([
                    'secret' => $secret,
                    'response' => $responseToken,
                    'remoteip' => $remoteIp,
                    'idempotency_key' => $idempotencyKey,
                ]));
        } catch (Throwable) {
            return new BotVerificationResult(false, null, null, ['verification-unavailable']);
        }
        if (! $response->successful()) {
            return new BotVerificationResult(false, null, null, ['verification-unavailable']);
        }

        $hostname = $response->json('hostname');
        $action = $response->json('action');
        $challengeAt = $response->json('challenge_ts');
        try {
            $challengeTimeValid = is_string($challengeAt)
                && CarbonImmutable::parse($challengeAt)->betweenIncluded(now()->subMinutes(5), now()->addMinute());
        } catch (Throwable) {
            $challengeTimeValid = false;
        }
        $valid = $response->json('success') === true
            && is_string($action) && hash_equals($expectedAction, $action)
            && is_string($hostname)
            && in_array($hostname, $allowedHostnames, true)
            && $challengeTimeValid;
        $safeCodes = collect($response->json('error-codes', []))
            ->filter('is_string')->map(fn (string $code): string => substr($code, 0, 80))->values()->all();

        return new BotVerificationResult($valid, is_string($hostname) ? $hostname : null, is_string($action) ? $action : null, $safeCodes);
    }

    private function secret(): string
    {
        return trim((string) config('direct-booking.turnstile_secret'));
    }

    /** @return list<string> */
    private function allowedHostnames(): array
    {
        return array_values(array_unique(array_filter(
            array_map(
                static fn (mixed $hostname): string => is_string($hostname) ? strtolower(trim($hostname)) : '',
                (array) config('direct-booking.turnstile_allowed_hostnames', []),
            ),
            static fn (string $hostname): bool => str_contains($hostname, '.')
                && filter_var($hostname, FILTER_VALIDATE_IP) === false
                && preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $hostname) === 1,
        )));
    }
}
