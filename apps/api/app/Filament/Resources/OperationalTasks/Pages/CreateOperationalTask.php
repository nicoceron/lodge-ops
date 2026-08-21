<?php

namespace App\Filament\Resources\OperationalTasks\Pages;

use App\Filament\Resources\OperationalTasks\OperationalTaskResource;
use App\Services\TaskLifecycleService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateOperationalTask extends CreateRecord
{
    protected static string $resource = OperationalTaskResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(TaskLifecycleService::class)->create($data, auth()->id());
    }
}
