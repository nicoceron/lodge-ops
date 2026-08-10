<?php

namespace App\Filament\Resources\OperationalTasks\Pages;

use App\Enums\TaskStatus;
use App\Filament\Resources\OperationalTasks\OperationalTaskResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOperationalTask extends CreateRecord
{
    protected static string $resource = OperationalTaskResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['completed_at'] = $data['status'] === TaskStatus::Done->value ? now() : null;

        return $data;
    }
}
