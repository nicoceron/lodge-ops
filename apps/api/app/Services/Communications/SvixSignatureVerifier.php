<?php

namespace App\Services\Communications;

use DomainException;

final class SvixSignatureVerifier
{
    /** @param array<string, string> $headers */
    public function verify(string $rawBody, array $headers, string $secret): string
    {
        $messageId = trim($headers['svix-id'] ?? '');
        $timestamp = trim($headers['svix-timestamp'] ?? '');
        $signatures = trim($headers['svix-signature'] ?? '');
        if ($messageId === '' || ! ctype_digit($timestamp) || $signatures === '') {
            throw new DomainException('Invalid webhook signature.');
        }
        if (abs(time() - (int) $timestamp) > (int) config('communications.provider.signature_tolerance_seconds', 300)) {
            throw new DomainException('Invalid webhook signature.');
        }

        $encoded = str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret;
        $key = base64_decode($encoded, true);
        if (! is_string($key) || $key === '') {
            throw new DomainException('Invalid webhook signature.');
        }

        $expected = base64_encode(hash_hmac('sha256', $messageId.'.'.$timestamp.'.'.$rawBody, $key, true));
        foreach (preg_split('/\s+/', $signatures) ?: [] as $candidate) {
            [$version, $value] = array_pad(explode(',', $candidate, 2), 2, '');
            if ($version === 'v1' && $value !== '' && hash_equals($expected, $value)) {
                return $messageId;
            }
        }

        throw new DomainException('Invalid webhook signature.');
    }
}
