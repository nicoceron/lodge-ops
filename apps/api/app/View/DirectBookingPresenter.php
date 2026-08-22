<?php

namespace App\View;

use Carbon\CarbonImmutable;

final class DirectBookingPresenter
{
    public static function money(array $money, string $locale): string
    {
        $amount = (int) ($money['amount_minor'] ?? 0);
        $currency = strtoupper((string) ($money['currency'] ?? ''));
        $negative = $amount < 0;
        $absolute = abs($amount);
        $major = intdiv($absolute, 100);
        $minor = $absolute % 100;
        $spanish = str_starts_with($locale, 'es');
        $majorText = number_format($major, 0, $spanish ? ',' : '.', $spanish ? '.' : ',');
        $formatted = $majorText.($spanish ? ',' : '.').str_pad((string) $minor, 2, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '').$currency.' '.$formatted;
    }

    public static function date(?string $date, string $locale): string
    {
        if ($date === null || $date === '') {
            return '';
        }

        return CarbonImmutable::createFromFormat('Y-m-d', $date)->locale($locale)->isoFormat('LL');
    }

    public static function dateTime(?string $dateTime, string $timezone, string $locale): string
    {
        if ($dateTime === null || $dateTime === '') {
            return '';
        }

        return CarbonImmutable::parse($dateTime)->timezone($timezone)->locale($locale)->isoFormat('LLL');
    }
}
