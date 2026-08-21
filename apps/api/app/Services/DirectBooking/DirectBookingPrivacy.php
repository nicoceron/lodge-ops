<?php

namespace App\Services\DirectBooking;

use Illuminate\Support\Arr;
use InvalidArgumentException;

final class DirectBookingPrivacy
{
    /** @var list<string> */
    public const ATTRIBUTION_KEYS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'referrer_host', 'landing_path',
    ];

    /** @param array<string, mixed> $input @return array<string, string> */
    public function attribution(array $input): array
    {
        $result = [];
        foreach (Arr::only($input, self::ATTRIBUTION_KEYS) as $key => $value) {
            if (! is_scalar($value)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value === '' || strlen($value) > 200 || preg_match('/[\r\n\x00-\x1F]/', $value)) {
                continue;
            }
            if ($key === 'landing_path' && (! str_starts_with($value, '/') || str_contains($value, '?'))) {
                continue;
            }
            if ($key === 'referrer_host' && ! preg_match('/^[A-Za-z0-9.-]+$/', $value)) {
                continue;
            }
            $result[$key] = $value;
        }

        return $result;
    }

    public function ipPrefixHash(?string $ip): ?string
    {
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $binary = inet_pton($ip);
        if ($binary === false) {
            return null;
        }
        $prefix = strlen($binary) === 4
            ? substr($binary, 0, 3)
            : substr($binary, 0, 7);

        return hash_hmac('sha256', $prefix, (string) config('app.key'));
    }

    /** @param array<string, mixed> $metadata @return array<string, scalar|null> */
    public function safeEventMetadata(array $metadata): array
    {
        $allowed = [
            'reason_code', 'failure_code', 'provider_event_reference_hash', 'reservation_revision',
            'payment_request_reference', 'scheduler_outcome', 'recovery_mode', 'hold_extension_minutes',
        ];
        $unknown = array_diff(array_keys($metadata), $allowed);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Direct-booking events may contain only allowlisted non-PII facts.');
        }
        foreach ($metadata as $value) {
            if (! is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException('Direct-booking event facts must be scalar and non-PII.');
            }
            if (is_string($value) && (strlen($value) > 160 || str_contains($value, '@'))) {
                throw new InvalidArgumentException('Direct-booking event facts must not contain contact details.');
            }
        }

        return $metadata;
    }
}
