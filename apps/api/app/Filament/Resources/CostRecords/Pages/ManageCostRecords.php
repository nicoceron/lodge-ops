<?php

namespace App\Filament\Resources\CostRecords\Pages;

use App\Filament\Resources\CostRecords\CostRecordResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCostRecords extends ManageRecords
{
    protected static string $resource = CostRecordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
