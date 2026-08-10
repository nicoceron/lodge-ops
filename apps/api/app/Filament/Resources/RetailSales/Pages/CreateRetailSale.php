<?php

namespace App\Filament\Resources\RetailSales\Pages;

use App\Filament\Resources\RetailSales\RetailSaleResource;
use App\Models\Reservation;
use App\Models\StockLocation;
use App\Services\RetailPostingService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateRetailSale extends CreateRecord
{
    protected static string $resource = RetailSaleResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(RetailPostingService::class)->post(
            StockLocation::query()->findOrFail($data['stock_location_id']),
            $data['reference'],
            $data['lines'],
            filled($data['reservation_id'] ?? null) ? Reservation::query()->findOrFail($data['reservation_id']) : null,
            (int) ($data['tax_minor'] ?? 0),
        );
    }
}
