<?php

namespace App\Filament\Resources\FolioLines\Pages;

use App\Filament\Resources\FolioLines\FolioLineResource;
use App\Filament\Resources\FolioLines\FolioWorkflowActions;
use Filament\Resources\Pages\ListRecords;

class ListFolioLines extends ListRecords
{
    protected static string $resource = FolioLineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            FolioWorkflowActions::append(),
        ];
    }
}
