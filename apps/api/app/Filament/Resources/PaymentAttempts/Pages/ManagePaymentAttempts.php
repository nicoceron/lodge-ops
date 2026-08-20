<?php

namespace App\Filament\Resources\PaymentAttempts\Pages;

use App\Filament\Resources\PaymentAttempts\PaymentAttemptResource;
use Filament\Resources\Pages\ManageRecords;

class ManagePaymentAttempts extends ManageRecords
{
    protected static string $resource = PaymentAttemptResource::class;
}
