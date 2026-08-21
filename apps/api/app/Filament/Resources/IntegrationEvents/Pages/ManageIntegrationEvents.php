<?php

namespace App\Filament\Resources\IntegrationEvents\Pages;

use App\Filament\Resources\IntegrationEvents\IntegrationEventResource;
use Filament\Resources\Pages\ManageRecords;

class ManageIntegrationEvents extends ManageRecords
{
    protected static string $resource = IntegrationEventResource::class;
}
