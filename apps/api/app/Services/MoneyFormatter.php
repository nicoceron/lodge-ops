<?php

namespace App\Services;

use NumberFormatter;
use RuntimeException;

final class MoneyFormatter
{
    public function formatMinor(int $amountMinor, string $currency, ?string $locale = null): string
    {
        $formatter = new NumberFormatter($locale ?: app()->getLocale(), NumberFormatter::CURRENCY);
        $formatted = $formatter->formatCurrency($amountMinor / 100, strtoupper($currency));

        if ($formatted === false) {
            throw new RuntimeException('Unable to format money for the requested locale and currency.');
        }

        return $formatted;
    }
}
