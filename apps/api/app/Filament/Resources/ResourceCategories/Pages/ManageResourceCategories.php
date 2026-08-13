<?php

namespace App\Filament\Resources\ResourceCategories\Pages;

use App\Filament\Resources\ResourceCategories\ResourceCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageResourceCategories extends ManageRecords
{
    protected static string $resource = ResourceCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
