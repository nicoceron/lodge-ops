<?php

namespace App\Enums;

enum PaymentRequestState: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Processing = 'processing';
    case Paid = 'paid';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case Superseded = 'superseded';
}
