<?php

namespace App\Filament\Resources\ExchangeRates\Pages;

use App\Filament\Resources\ExchangeRates\ExchangeRateResource;
use App\Services\ExchangeRateService;
use App\Support\Tenancy\TenantContext;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageExchangeRates extends ManageRecords
{
    protected static string $resource = ExchangeRateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->using(fn (array $data) => app(ExchangeRateService::class)->snapshot(
                    $data['base_currency'],
                    $data['quote_currency'],
                    (string) $data['rate'],
                    $data['source'],
                    new \DateTimeImmutable($data['effective_at']),
                    $data['property_id'] ?? app(TenantContext::class)->membership()?->property_id,
                )),
        ];
    }
}
