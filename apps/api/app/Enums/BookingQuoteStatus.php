<?php

namespace App\Enums;

enum BookingQuoteStatus: string
{
    case Pending = 'pending';
    case Committed = 'committed';
    case Expired = 'expired';
    case Superseded = 'superseded';
}
