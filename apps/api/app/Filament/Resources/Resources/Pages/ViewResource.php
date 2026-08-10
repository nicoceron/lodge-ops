<?php

namespace App\Filament\Resources\Resources\Pages;

use App\Filament\Resources\Resources\ResourceResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewResource extends ViewRecord
{
    protected static string $resource = ResourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
