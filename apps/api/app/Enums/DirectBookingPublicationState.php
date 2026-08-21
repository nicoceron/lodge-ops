<?php

namespace App\Enums;

enum DirectBookingPublicationState: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Retired = 'retired';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
