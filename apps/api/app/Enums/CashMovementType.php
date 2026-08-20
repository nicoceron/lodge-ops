<?php

namespace App\Enums;

enum CashMovementType: string
{
    case OpeningFloat = 'opening_float';
    case Payment = 'payment';
    case Refund = 'refund';
    case PayIn = 'pay_in';
    case PayOut = 'pay_out';
    case Correction = 'correction';
}
