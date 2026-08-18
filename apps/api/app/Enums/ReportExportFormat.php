<?php

namespace App\Enums;

enum ReportExportFormat: string
{
    case Csv = 'csv';
    case Xlsx = 'xlsx';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
