<?php

namespace App\Filament\Resources\CommunicationProviderConnections\Pages;

use App\Filament\Resources\CommunicationProviderConnections\CommunicationProviderConnectionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCommunicationProviderConnections extends ManageRecords
{
    protected static string $resource = CommunicationProviderConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
