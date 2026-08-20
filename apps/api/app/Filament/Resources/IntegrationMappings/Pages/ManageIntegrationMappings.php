<?php

namespace App\Filament\Resources\IntegrationMappings\Pages;

use App\Filament\Resources\IntegrationMappings\IntegrationMappingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageIntegrationMappings extends ManageRecords
{
    protected static string $resource = IntegrationMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->mutateDataUsing(function (array $data): array {
            $data['valid_from'] = now();
            $data['conflict_state'] = 'clear';
            $data['facts_checksum'] = hash('sha256', json_encode($data['safe_facts'] ?? [], JSON_THROW_ON_ERROR));

            return $data;
        })];
    }
}
