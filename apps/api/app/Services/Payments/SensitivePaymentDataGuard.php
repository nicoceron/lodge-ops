<?php

namespace App\Services\Payments;

use Illuminate\Validation\ValidationException;

final class SensitivePaymentDataGuard
{
    public function assertSafe(mixed $value, string $field = 'payload'): void
    {
        foreach ($this->strings($value, $field) as [$path, $text]) {
            if ($this->containsSensitiveAuthenticationData($text)) {
                throw ValidationException::withMessages([
                    $path => 'Do not store card verification codes, PINs, expiry data, or magnetic-stripe/chip track data in Inn.',
                ]);
            }

            if (preg_match('/(?:^|\.)(?:id|[a-z_]+_id|phone|sha256|[a-z_]*(?:checksum|hash))$/i', $path) === 1) {
                continue;
            }
            $withoutUuids = preg_replace('/\b[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b/i', '', $text) ?? $text;
            preg_match_all('/(?<!\d)(?:\d[ -]?){13,19}(?!\d)/', $withoutUuids, $matches);
            foreach ($matches[0] as $candidate) {
                $digits = preg_replace('/\D/', '', $candidate) ?? '';
                if ($this->luhn($digits)) {
                    throw ValidationException::withMessages([
                        $path => "The {$path} value resembles a full payment-card number. Store only receipt-safe references and, where supported, the last four digits.",
                    ]);
                }
            }
        }
    }

    private function containsSensitiveAuthenticationData(string $value): bool
    {
        return preg_match('/(?:cvv2?|cvc2?|cid|card\s*verification|security\s*code|pin)\s*[:=#-]?\s*\d{3,12}/i', $value) === 1
            || preg_match('/(?:exp(?:iry|iration)?|valid\s*(?:thru|through))\s*[:=#-]?\s*(?:0[1-9]|1[0-2])[\/-](?:\d{2}|\d{4})/i', $value) === 1
            || preg_match('/(?<!\d)(?:0[1-9]|1[0-2])\/(?:\d{2}|\d{4})(?!\d)/', $value) === 1
            || preg_match('/(?:track\s*[12]\s*[:=#-]?\s*)?(?:%B|;)[0-9]{12,19}[\^=D]/i', $value) === 1;
    }

    /** @return iterable<array{string, string}> */
    private function strings(mixed $value, string $path): iterable
    {
        if (is_string($value)) {
            yield [$path, $value];

            return;
        }
        if (! is_array($value)) {
            return;
        }
        foreach ($value as $key => $item) {
            yield from $this->strings($item, $path === '' ? (string) $key : $path.'.'.(string) $key);
        }
    }

    private function luhn(string $digits): bool
    {
        if (strlen($digits) < 13 || strlen($digits) > 19 || preg_match('/^(\d)\1+$/', $digits)) {
            return false;
        }
        $sum = 0;
        $parity = strlen($digits) % 2;
        foreach (str_split($digits) as $index => $digit) {
            $number = (int) $digit;
            if ($index % 2 === $parity && ($number *= 2) > 9) {
                $number -= 9;
            }
            $sum += $number;
        }

        return $sum % 10 === 0;
    }
}
