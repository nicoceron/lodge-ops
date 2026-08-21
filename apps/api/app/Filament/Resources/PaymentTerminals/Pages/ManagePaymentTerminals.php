<?php

namespace App\Filament\Resources\PaymentTerminals\Pages;

use App\Filament\Resources\PaymentTerminals\PaymentTerminalResource;
use Filament\Resources\Pages\ManageRecords;

class ManagePaymentTerminals extends ManageRecords
{
    protected static string $resource = PaymentTerminalResource::class;
}
