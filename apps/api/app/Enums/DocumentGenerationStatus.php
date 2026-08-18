<?php

namespace App\Enums;

enum DocumentGenerationStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Generated = 'generated';
    case Failed = 'failed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
