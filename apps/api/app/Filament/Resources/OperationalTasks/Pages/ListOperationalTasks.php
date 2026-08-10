<?php

namespace App\Filament\Resources\OperationalTasks\Pages;

use App\Filament\Resources\OperationalTasks\OperationalTaskResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOperationalTasks extends ListRecords
{
    protected static string $resource = OperationalTaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
