<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case Draft = 'draft';
    case Hold = 'hold';
    case Confirmed = 'confirmed';
    case CheckedIn = 'checked_in';
    case CheckedOut = 'checked_out';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Draft => in_array($next, [self::Hold, self::Confirmed, self::Cancelled], true),
            self::Hold => in_array($next, [self::Draft, self::Confirmed, self::Cancelled], true),
            self::Confirmed => in_array($next, [self::CheckedIn, self::Cancelled, self::NoShow], true),
            self::CheckedIn => in_array($next, [self::CheckedOut], true),
            default => false,
        };
    }
}
