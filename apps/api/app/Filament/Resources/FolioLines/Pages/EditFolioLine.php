<?php

namespace App\Filament\Resources\FolioLines\Pages;

use App\Filament\Resources\FolioLines\FolioLineResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditFolioLine extends EditRecord
{
    protected static string $resource = FolioLineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
