<?php

namespace App\Enums;

enum PaymentOrigin: string
{
    case Manual = 'manual';
    case Provider = 'provider';
}
