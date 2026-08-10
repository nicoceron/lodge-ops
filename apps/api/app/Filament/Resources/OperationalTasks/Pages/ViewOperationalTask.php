<?php

namespace App\Filament\Resources\OperationalTasks\Pages;

use App\Filament\Resources\OperationalTasks\OperationalTaskResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewOperationalTask extends ViewRecord
{
    protected static string $resource = OperationalTaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
