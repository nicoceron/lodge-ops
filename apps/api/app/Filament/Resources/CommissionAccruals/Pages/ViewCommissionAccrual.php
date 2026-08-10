<?php

namespace App\Filament\Resources\CommissionAccruals\Pages;

use App\Filament\Resources\CommissionAccruals\CommissionAccrualResource;
use Filament\Resources\Pages\ViewRecord;

class ViewCommissionAccrual extends ViewRecord
{
    protected static string $resource = CommissionAccrualResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CommissionAccrualResource::markPaidAction(),
        ];
    }
}
