<?php

namespace App\Filament\Resources\Reservations\Tables;

use App\Enums\ReservationStatus;
use App\Filament\Resources\Reservations\ReservationWorkflowActions;
use App\Filament\Support\InnPresentation;
use App\Models\Reservation;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ReservationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('confirmation_number')
                    ->label('Confirmation')
                    ->copyable()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('primaryGuest.first_name')
                    ->label('Primary guest')
                    ->formatStateUsing(fn ($state, Reservation $record): string => $record->primaryGuest
                        ? trim("{$record->primaryGuest->first_name} {$record->primaryGuest->last_name}")
                        : 'Unassigned')
                    ->description(fn (Reservation $record): ?string => $record->primaryGuest?->email)
                    ->searchable(['first_name', 'last_name', 'email']),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(InnPresentation::label(...))
                    ->color(fn ($state): string => InnPresentation::statusColor($state))
                    ->sortable(),
                TextColumn::make('starts_at')
                    ->label('Arrival')
                    ->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())
                    ->description(fn (Reservation $record): string => $record->property->name)
                    ->sortable(),
                TextColumn::make('ends_at')
                    ->label('Departure')
                    ->date('M j, Y', timezone: InnPresentation::timezone())
                    ->sortable(),
                TextColumn::make('party')
                    ->state(fn (Reservation $record): string => ($record->adults + $record->children).' guests')
                    ->alignCenter(),
                TextColumn::make('total_minor')
                    ->label('Total')
                    ->money(fn (Reservation $record): string => $record->currency, divideBy: 100)
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('property')
                    ->relationship('property', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('status')
                    ->options(InnPresentation::enumOptions(ReservationStatus::cases()))
                    ->multiple(),
                Filter::make('upcoming')
                    ->label('Upcoming stays')
                    ->query(fn (Builder $query): Builder => $query->where('ends_at', '>=', now())),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ActionGroup::make(ReservationWorkflowActions::make())
                    ->label('Change status')
                    ->icon('heroicon-m-arrows-right-left'),
            ])
            ->defaultSort('starts_at')
            ->striped()
            ->emptyStateHeading('No reservations in this view')
            ->emptyStateDescription('Create a reservation or clear the filters to see the full book.')
            ->emptyStateIcon('heroicon-o-calendar-days');
    }
}
