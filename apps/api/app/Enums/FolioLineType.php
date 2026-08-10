<?php

namespace App\Enums;

enum FolioLineType: string
{
    case Charge = 'charge';
    case Payment = 'payment';
    case Refund = 'refund';
    case Adjustment = 'adjustment';
}
