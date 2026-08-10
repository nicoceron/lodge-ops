<?php

namespace App\Filament\Resources\StockLocations\Pages;

use App\Filament\Resources\StockLocations\StockLocationResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditStockLocation extends EditRecord
{
    protected static string $resource = StockLocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
