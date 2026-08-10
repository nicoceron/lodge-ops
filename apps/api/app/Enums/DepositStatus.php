<?php

namespace App\Enums;

enum DepositStatus: string
{
    case Due = 'due';
    case Paid = 'paid';
    case Waived = 'waived';
    case Refunded = 'refunded';
}
