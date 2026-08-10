<?php

namespace App\Enums;

enum AllocationStatus: string
{
    case Tentative = 'tentative';
    case Confirmed = 'confirmed';
    case Released = 'released';
}
