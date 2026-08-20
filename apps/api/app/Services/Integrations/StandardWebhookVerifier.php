<?php

namespace App\Services\Integrations;

use RuntimeException;

final class StandardWebhookVerifier
{
    /** @param array<string,string> $headers */
    public function verify(string $rawBody, array $headers, string $secret, int $toleranceSeconds = 300): void
    {
        $id = $headers['webhook-id'] ?? '';
        $timestamp = $headers['webhook-timestamp'] ?? '';
        $signatures = $headers['webhook-signature'] ?? '';
        if ($id === '' || preg_match('/^\d{10}$/', $timestamp) !== 1 || $signatures === '') {
            throw new RuntimeException('Missing Standard Webhooks signature headers.');
        }
        if (abs(time() - (int) $timestamp) > $toleranceSeconds) {
            throw new RuntimeException('The Standard Webhooks timestamp is stale.');
        }
        $encoded = str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret;
        $key = base64_decode($encoded, true);
        if ($key === false || $key === '') {
            throw new RuntimeException('The Standard Webhooks signing secret is malformed.');
        }
        $expected = base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$rawBody, $key, true));
        foreach (preg_split('/\s+/', trim($signatures)) ?: [] as $candidate) {
            [$version, $signature] = array_pad(explode(',', $candidate, 2), 2, '');
            if ($version === 'v1' && $signature !== '' && hash_equals($expected, $signature)) {
                return;
            }
        }

        throw new RuntimeException('The Standard Webhooks signature is invalid.');
    }
}
