<?php

namespace App\Services\DirectBooking;

use App\Contracts\DirectBooking\BotVerifier;
use App\Data\DirectBooking\BotVerificationResult;
use Illuminate\Support\Facades\Http;

final class CloudflareTurnstileVerifier implements BotVerifier
{
    public function verify(string $responseToken, ?string $remoteIp, string $expectedAction, string $idempotencyKey): BotVerificationResult
    {
        $secret = (string) config('direct-booking.turnstile_secret');
        if ($secret === '' || $responseToken === '') {
            return new BotVerificationResult(false, null, null, ['missing-input']);
        }

        $response = Http::asForm()
            ->acceptJson()
            ->timeout((int) config('direct-booking.turnstile_timeout_seconds', 5))
            ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', array_filter([
                'secret' => $secret,
                'response' => $responseToken,
                'remoteip' => $remoteIp,
                'idempotency_key' => $idempotencyKey,
            ]));
        if (! $response->successful()) {
            return new BotVerificationResult(false, null, null, ['verification-unavailable']);
        }

        $hostname = $response->json('hostname');
        $action = $response->json('action');
        $allowedHostnames = config('direct-booking.turnstile_allowed_hostnames', []);
        $valid = $response->json('success') === true
            && is_string($action) && hash_equals($expectedAction, $action)
            && is_string($hostname)
            && (empty($allowedHostnames) || in_array($hostname, $allowedHostnames, true));
        $safeCodes = collect($response->json('error-codes', []))
            ->filter('is_string')->map(fn (string $code): string => substr($code, 0, 80))->values()->all();

        return new BotVerificationResult($valid, is_string($hostname) ? $hostname : null, is_string($action) ? $action : null, $safeCodes);
    }
}
