<?php

namespace App\Filament\Resources\PaymentEvidence\Pages;

use App\Filament\Resources\PaymentEvidence\PaymentEvidenceResource;
use Filament\Resources\Pages\ViewRecord;

class ViewPaymentEvidence extends ViewRecord
{
    protected static string $resource = PaymentEvidenceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PaymentEvidenceResource::downloadAction(),
            PaymentEvidenceResource::approveAction(),
            PaymentEvidenceResource::requestInformationAction(),
            PaymentEvidenceResource::rejectAction(),
        ];
    }
}
