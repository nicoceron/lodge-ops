<?php

namespace App\Filament\Resources\ResourceBlocks\Pages;

use App\Filament\Resources\ResourceBlocks\ResourceBlockResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageResourceBlocks extends ManageRecords
{
    protected static string $resource = ResourceBlockResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
