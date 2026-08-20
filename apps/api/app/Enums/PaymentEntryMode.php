<?php

namespace App\Enums;

enum PaymentEntryMode: string
{
    case StaffRecorded = 'staff_recorded';
    case ProviderReported = 'provider_reported';
}
