<?php

namespace App\Filament\Resources\RetailSales\Pages;

use App\Filament\Resources\RetailSales\RetailSaleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRetailSales extends ListRecords
{
    protected static string $resource = RetailSaleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
