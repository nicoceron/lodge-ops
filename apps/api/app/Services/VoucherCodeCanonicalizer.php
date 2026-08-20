<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;
use Normalizer;

final class VoucherCodeCanonicalizer
{
    public const NORMALIZATION_FORM = 'NFC';

    public const MIN_LENGTH = 6;

    public const MAX_LENGTH = 64;

    public function canonicalize(string $code): string
    {
        $normalized = class_exists(Normalizer::class)
            ? Normalizer::normalize($code, Normalizer::FORM_C)
            : $code;
        $canonical = mb_strtoupper(preg_replace('/^\s+|\s+$/u', '', (string) $normalized) ?? '');

        if (mb_strlen($canonical) < self::MIN_LENGTH || mb_strlen($canonical) > self::MAX_LENGTH
            || preg_match('/^[\p{L}\p{N}][\p{L}\p{N}-]*[\p{L}\p{N}]$/u', $canonical) !== 1) {
            throw ValidationException::withMessages([
                'voucher_code' => 'The voucher code is invalid.',
            ]);
        }

        return $canonical;
    }

    public function hash(string $tenantId, string $code): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true) ?: $key;
        }

        return hash_hmac('sha256', $tenantId."\0".$this->canonicalize($code), $key);
    }
}
