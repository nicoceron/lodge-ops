<?php

namespace App\Filament\Resources\IntegrationConnections\Pages;

use App\Filament\Resources\IntegrationConnections\IntegrationConnectionResource;
use App\Services\IntegrationConnectionService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageIntegrationConnections extends ManageRecords
{
    protected static string $resource = IntegrationConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->using(fn (array $data) => app(IntegrationConnectionService::class)->configure(
                    $data['name'],
                    $data['type'],
                    $data['configuration'] ?? [],
                    $data['secret_reference'] ?? null,
                    $data['property_id'] ?? null,
                    $data['provider'] ?? null,
                    $data['product'] ?? null,
                    $data['external_account_id'] ?? null,
                    $data['environment'] ?? null,
                    $data['capabilities'] ?? [],
                )),
        ];
    }
}
