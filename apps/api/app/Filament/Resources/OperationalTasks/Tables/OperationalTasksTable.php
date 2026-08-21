<?php

namespace App\Filament\Resources\OperationalTasks\Tables;

use App\Enums\TaskStatus;
use App\Filament\Support\InnPresentation;
use App\Models\OperationalTask;
use App\Services\OperationalTaskAssigneeService;
use App\Services\TaskLifecycleService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OperationalTasksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->description(fn ($record): string => $record->property->name)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(InnPresentation::label(...))
                    ->color(fn ($state): string => InnPresentation::statusColor($state))
                    ->sortable(),
                TextColumn::make('priority')
                    ->badge()
                    ->formatStateUsing(InnPresentation::label(...))
                    ->color(fn (?string $state): string => InnPresentation::priorityColor($state))
                    ->sortable(),
                TextColumn::make('assignee.name')
                    ->label('Owner')
                    ->placeholder('Unassigned')
                    ->searchable(),
                TextColumn::make('reservation.confirmation_number')
                    ->label('Reservation')
                    ->placeholder('General')
                    ->searchable(),
                TextColumn::make('due_at')
                    ->label('Due')
                    ->dateTime('M j · H:i', timezone: InnPresentation::timezone())
                    ->placeholder('No deadline')
                    ->sortable()
                    ->color(fn ($record): string => $record->due_at?->isPast() && ! in_array($record->status, [TaskStatus::Done, TaskStatus::Cancelled, TaskStatus::Superseded], true)
                        ? 'danger'
                        : 'gray'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(InnPresentation::enumOptions(TaskStatus::cases()))
                    ->multiple(),
                SelectFilter::make('priority')
                    ->options([
                        'low' => 'Low',
                        'normal' => 'Normal',
                        'high' => 'High',
                        'urgent' => 'Urgent',
                    ])
                    ->multiple(),
                SelectFilter::make('assignee')
                    ->relationship('assignee', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('overdue')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('due_at', '<', now())
                        ->whereNotIn('status', [TaskStatus::Done->value, TaskStatus::Cancelled->value, TaskStatus::Superseded->value])),
            ])
            ->recordActions([
                Action::make('assign')
                    ->icon('heroicon-o-user-plus')
                    ->color('gray')
                    ->visible(fn (OperationalTask $record): bool => ! in_array($record->status, [TaskStatus::Done, TaskStatus::Cancelled, TaskStatus::Superseded], true))
                    ->fillForm(fn (OperationalTask $record): array => ['assignee_id' => $record->assignee_id])
                    ->schema([
                        Select::make('assignee_id')->label('Eligible assignee')
                            ->options(fn (OperationalTask $record): array => app(OperationalTaskAssigneeService::class)->eligibleOptions($record))
                            ->searchable()->preload()->required(),
                    ])
                    ->action(fn (OperationalTask $record, array $data) => self::transition($record, 'assign', $data)),
                Action::make('start')
                    ->icon('heroicon-o-play')
                    ->color('info')
                    ->visible(fn (OperationalTask $record): bool => in_array($record->status, [TaskStatus::Todo, TaskStatus::Blocked], true))
                    ->action(fn (OperationalTask $record) => self::transition($record, 'start')),
                Action::make('complete')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (OperationalTask $record): bool => in_array($record->status, [TaskStatus::Todo, TaskStatus::InProgress, TaskStatus::Blocked], true))
                    ->requiresConfirmation()
                    ->action(fn (OperationalTask $record) => self::transition($record, 'complete')),
                Action::make('fail')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (OperationalTask $record): bool => in_array($record->status, [TaskStatus::Todo, TaskStatus::InProgress, TaskStatus::Blocked], true))
                    ->schema([Textarea::make('reason')->required()->maxLength(2000)])
                    ->action(fn (OperationalTask $record, array $data) => self::transition($record, 'fail', $data)),
                Action::make('escalate')
                    ->icon('heroicon-o-arrow-trending-up')
                    ->color('warning')
                    ->visible(fn (OperationalTask $record): bool => ! in_array($record->status, [TaskStatus::Done, TaskStatus::Cancelled, TaskStatus::Superseded], true))
                    ->schema([Textarea::make('reason')->required()->maxLength(2000)])
                    ->action(fn (OperationalTask $record, array $data) => self::transition($record, 'escalate', $data)),
                Action::make('reopen')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (OperationalTask $record): bool => in_array($record->status, [TaskStatus::Done, TaskStatus::Failed, TaskStatus::Cancelled], true))
                    ->requiresConfirmation()
                    ->action(fn (OperationalTask $record) => self::transition($record, 'reopen')),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->defaultSort('due_at')
            ->striped()
            ->emptyStateHeading('Work queue is clear')
            ->emptyStateDescription('New housekeeping, guide, kitchen and follow-up tasks will appear here.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }

    /** @param array<string, mixed> $data */
    private static function transition(OperationalTask $task, string $action, array $data = []): void
    {
        app(TaskLifecycleService::class)->transition($task, $action, [
            ...$data,
            'expected_revision' => $task->revision,
        ], auth()->id());
        Notification::make()->success()->title('Task '.str_replace('_', ' ', $action).' recorded')->send();
    }
}
