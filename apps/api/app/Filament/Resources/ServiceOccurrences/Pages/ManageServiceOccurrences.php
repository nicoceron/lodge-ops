<?php

namespace App\Filament\Resources\ServiceOccurrences\Pages;

use App\Filament\Resources\ServiceOccurrences\ServiceOccurrenceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageServiceOccurrences extends ManageRecords
{
    protected static string $resource = ServiceOccurrenceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
