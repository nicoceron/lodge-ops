<?php

namespace App\Services\Payments;

use Illuminate\Validation\ValidationException;

final class SensitivePaymentDataGuard
{
    /** @var list<string> */
    private const RESOLVABLE_REFERENCE_FIELDS = ['transaction_reference', 'authorization_reference', 'batch_reference'];

    /** @var list<string> */
    private array $scopedLuhnFalsePositiveFields = [];

    /** @param list<string> $luhnFalsePositiveFields */
    public function assertSafe(mixed $value, string $field = 'payload', array $luhnFalsePositiveFields = []): void
    {
        $resolvedFields = array_values(array_unique([...$this->scopedLuhnFalsePositiveFields, ...$this->validateResolutionFields($luhnFalsePositiveFields)]));
        foreach ($this->strings($value, $field) as [$path, $text]) {
            if ($this->isGeneratedStorageLocator($path, $text)) {
                continue;
            }
            $withoutUuids = preg_replace('/(?<![0-9a-f])[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}(?![0-9a-f])/i', '', $text) ?? $text;
            if ($this->containsSensitiveAuthenticationData($withoutUuids)) {
                throw ValidationException::withMessages([
                    $path => 'Do not store card verification codes, PINs, expiry data, or magnetic-stripe/chip track data in Inn.',
                ]);
            }

            if ($this->isGeneratedHash($path, $text)) {
                continue;
            }

            if (preg_match('/(?:^|\.)(?:id|[a-z_]+_id|phone|sha256|[a-z_]*(?:checksum|hash))$/i', $path) === 1) {
                continue;
            }
            if (preg_match('/(?:^|\.)(?:deduplication|idempotency)_key$/i', $path) === 1 && preg_match('/\A[0-9a-f]{64}\z/i', $text) === 1) {
                continue;
            }
            preg_match_all('/(?<!\d)(?:\d[ -]?){13,19}(?!\d)/', $withoutUuids, $matches);
            foreach ($matches[0] as $candidate) {
                $digits = preg_replace('/\D/', '', $candidate) ?? '';
                if ($this->luhn($digits)) {
                    if ($this->isResolvedReferencePath($path, $resolvedFields)) {
                        continue;
                    }
                    throw ValidationException::withMessages([
                        $path => "The {$path} value resembles a full payment-card number. Store only receipt-safe references and, where supported, the last four digits.",
                    ]);
                }
            }
        }
    }

    /**
     * @template T
     *
     * @param  list<string>  $fields
     * @param  callable(): T  $callback
     * @return T
     */
    public function withLuhnFalsePositiveResolution(array $fields, string $justification, callable $callback): mixed
    {
        $fields = $this->validateLuhnFalsePositiveResolution($fields, $justification);
        $previous = $this->scopedLuhnFalsePositiveFields;
        $this->scopedLuhnFalsePositiveFields = $fields;
        try {
            return $callback();
        } finally {
            $this->scopedLuhnFalsePositiveFields = $previous;
        }
    }

    /** @param list<string> $fields @return list<string> */
    public function validateLuhnFalsePositiveResolution(array $fields, string $justification): array
    {
        $fields = $this->validateResolutionFields($fields);
        $justification = trim($justification);
        if ($fields === [] || mb_strlen($justification) < 20 || mb_strlen($justification) > 500) {
            throw ValidationException::withMessages([
                'luhn_false_positive_justification' => 'A 20-500 character justification is required for a Luhn false-positive resolution.',
            ]);
        }
        $this->assertSafe($justification, 'luhn_false_positive_justification');

        return $fields;
    }

    private function containsSensitiveAuthenticationData(string $value): bool
    {
        return preg_match('/(?:cvv2?|cvc2?|cid|card\s*verification|security\s*code|pin)\s*[:=#-]?\s*\d{3,12}/i', $value) === 1
            || preg_match('/(?:exp(?:iry|iration)?|valid\s*(?:thru|through))\s*[:=#-]?\s*(?:0[1-9]|1[0-2])[\/-](?:\d{2}|\d{4})/i', $value) === 1
            || preg_match('/(?<!\d)(?:0[1-9]|1[0-2])\/(?:\d{2}|\d{4})(?!\d)/', $value) === 1
            || preg_match('/(?:track\s*[12]\s*[:=#-]?\s*)?(?:%B|;)[0-9]{12,19}[\^=D]/i', $value) === 1;
    }

    private function isGeneratedHash(string $path, string $value): bool
    {
        return preg_match('/(?:^|\.)(?:document_email_key|command_key)$/i', $path) === 1
            && preg_match('/\A[0-9a-f]{64}\z/i', $value) === 1;
    }

    private function isGeneratedStorageLocator(string $path, string $value): bool
    {
        if (preg_match('/(?:^|\.)storage_(?:path|key)$/i', $path) !== 1) {
            return false;
        }
        $uuid = '[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';

        return preg_match("#\\Aguest-payment-evidence/{$uuid}/{$uuid}/{$uuid}\\.(?:pdf|png|jpe?g)\\z#i", $value) === 1
            || preg_match("#\\Apayment-evidence/{$uuid}/refunds/{$uuid}/[0-9a-f]{64}\\.(?:pdf|png|jpe?g)\\z#i", $value) === 1;
    }

    /** @return iterable<array{string, string}> */
    private function strings(mixed $value, string $path): iterable
    {
        if (is_string($value)) {
            if (in_array($value[0] ?? '', ['{', '['], true)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    yield from $this->strings($decoded, $path);

                    return;
                }
            }
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

    /** @param list<mixed> $fields @return list<string> */
    private function validateResolutionFields(array $fields): array
    {
        $validated = [];
        foreach ($fields as $field) {
            if (! is_string($field)) {
                throw ValidationException::withMessages(['luhn_false_positive_fields' => 'Resolved fields must use approved reference names.']);
            }
            $validated[] = $field;
        }
        $fields = array_values(array_unique($validated));
        if (array_diff($fields, self::RESOLVABLE_REFERENCE_FIELDS) !== []) {
            throw ValidationException::withMessages([
                'luhn_false_positive_fields' => 'Only transaction, authorization, and batch references may resolve a documented Luhn false positive.',
            ]);
        }

        return $fields;
    }

    /** @param list<string> $resolvedFields */
    private function isResolvedReferencePath(string $path, array $resolvedFields): bool
    {
        foreach ($resolvedFields as $field) {
            if ($path === $field || $path === 'PaymentTenderDetail.'.$field) {
                return true;
            }
        }

        return false;
    }
}
