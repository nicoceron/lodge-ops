<?php

namespace App\Filament\Resources\OperationalTasks\Pages;

use App\Filament\Resources\OperationalTasks\OperationalTaskResource;
use App\Models\OperationalTask;
use App\Services\TaskLifecycleService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditOperationalTask extends EditRecord
{
    protected static string $resource = OperationalTaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            Action::make('cancel')
                ->label('Cancel task')
                ->color('danger')
                ->icon('heroicon-o-x-circle')
                ->visible(fn (): bool => $this->record instanceof OperationalTask
                    && ! in_array($this->record->status->value, ['done', 'cancelled', 'superseded'], true))
                ->schema([Textarea::make('reason')->required()->maxLength(2000)])
                ->action(function (array $data): void {
                    /** @var OperationalTask $task */
                    $task = $this->record;
                    app(TaskLifecycleService::class)->transition($task, 'cancel', [
                        ...$data,
                        'expected_revision' => $task->revision,
                    ], auth()->id());
                    Notification::make()->success()->title('Task cancelled with an audit event')->send();
                    $this->redirect(OperationalTaskResource::getUrl());
                }),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var OperationalTask $record */
        return app(TaskLifecycleService::class)->updateDetails($record, [
            ...$data,
            'expected_revision' => $record->revision,
        ], auth()->id());
    }
}
