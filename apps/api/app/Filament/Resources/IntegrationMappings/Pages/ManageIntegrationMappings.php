<?php

namespace App\Filament\Resources\IntegrationMappings\Pages;

use App\Filament\Resources\IntegrationMappings\IntegrationMappingResource;
use App\Models\IntegrationConnection;
use App\Services\Integrations\IntegrationMappingService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageIntegrationMappings extends ManageRecords
{
    protected static string $resource = IntegrationMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->using(fn (array $data) => app(IntegrationMappingService::class)->version(
            IntegrationConnection::query()->findOrFail($data['integration_connection_id']),
            $data['property_id'] ?? null,
            $data['capability'],
            $data['direction'],
            $data['local_entity_type'],
            $data['local_key'],
            $data['external_entity_type'],
            $data['external_key'],
            (int) $data['transform_version'],
            $data['safe_facts'] ?? [],
        ))];
    }
}
