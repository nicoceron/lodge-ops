<?php

namespace App\Filament\Resources\CommissionAccruals\Pages;

use App\Filament\Resources\CommissionAccruals\CommissionAccrualResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCommissionAccruals extends ListRecords
{
    protected static string $resource = CommissionAccrualResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
