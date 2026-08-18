<?php

namespace App\Filament\Resources\ProviderEvents\Pages;

use App\Filament\Resources\ProviderEvents\ProviderEventResource;
use Filament\Resources\Pages\ManageRecords;

class ManageProviderEvents extends ManageRecords
{
    protected static string $resource = ProviderEventResource::class;
}
