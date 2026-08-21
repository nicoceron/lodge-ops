<?php

namespace App\Filament\Resources\IntegrationRuns\Pages;

use App\Filament\Resources\IntegrationRuns\IntegrationRunResource;
use Filament\Resources\Pages\ManageRecords;

class ManageIntegrationRuns extends ManageRecords
{
    protected static string $resource = IntegrationRunResource::class;
}
