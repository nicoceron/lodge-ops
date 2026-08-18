<?php

namespace App\Services\Reports;

final class SpreadsheetSafety
{
    public static function value(mixed $value): bool|float|int|string|null
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }
        $value = (string) $value;
        if (preg_match('/^[\x00-\x20]*[=+\-@]/u', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }
}
