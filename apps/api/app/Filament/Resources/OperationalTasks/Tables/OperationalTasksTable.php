<?php

namespace App\Filament\Resources\OperationalTasks\Tables;

use App\Enums\TaskStatus;
use App\Filament\Support\InnPresentation;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
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
                    ->color(fn ($record): string => $record->due_at?->isPast() && ! in_array($record->status, [TaskStatus::Done, TaskStatus::Cancelled], true)
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
                        ->whereNotIn('status', [TaskStatus::Done->value, TaskStatus::Cancelled->value])),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->defaultSort('due_at')
            ->striped()
            ->emptyStateHeading('Work queue is clear')
            ->emptyStateDescription('New housekeeping, guide, kitchen and follow-up tasks will appear here.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
