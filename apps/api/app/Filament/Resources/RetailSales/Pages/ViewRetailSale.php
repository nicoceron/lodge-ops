<?php

namespace App\Filament\Resources\RetailSales\Pages;

use App\Filament\Resources\RetailSales\RetailSaleResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewRetailSale extends ViewRecord
{
    protected static string $resource = RetailSaleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
