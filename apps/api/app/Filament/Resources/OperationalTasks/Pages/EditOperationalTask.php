<?php

namespace App\Filament\Resources\OperationalTasks\Pages;

use App\Enums\TaskStatus;
use App\Filament\Resources\OperationalTasks\OperationalTaskResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditOperationalTask extends EditRecord
{
    protected static string $resource = OperationalTaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['completed_at'] = $data['status'] === TaskStatus::Done->value
            ? ($this->record->completed_at ?? now())
            : null;

        return $data;
    }
}
