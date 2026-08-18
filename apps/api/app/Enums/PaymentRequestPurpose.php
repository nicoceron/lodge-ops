<?php

namespace App\Enums;

enum PaymentRequestPurpose: string
{
    case Deposit = 'deposit';
    case Balance = 'balance';
    case FullOutstanding = 'full_outstanding';
    case AuthorizedPartial = 'authorized_partial';
}
