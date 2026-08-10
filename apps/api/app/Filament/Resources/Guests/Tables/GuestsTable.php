<?php

namespace App\Filament\Resources\Guests\Tables;

use App\Models\Guest;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class GuestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Guest')
                    ->state(fn (Guest $record): string => trim("{$record->first_name} {$record->last_name}"))
                    ->description(fn (Guest $record): ?string => $record->email)
                    ->searchable(['first_name', 'last_name', 'email'])
                    ->sortable(query: fn ($query, string $direction) => $query
                        ->orderBy('last_name', $direction)
                        ->orderBy('first_name', $direction)),
                TextColumn::make('phone')
                    ->icon('heroicon-m-phone')
                    ->placeholder('Not provided')
                    ->searchable(),
                TextColumn::make('language')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => strtoupper($state ?: '—'))
                    ->color('gray'),
                TextColumn::make('reservations_count')
                    ->label('Stays')
                    ->counts('reservations')
                    ->badge()
                    ->sortable(),
                IconColumn::make('marketing_consent')
                    ->label('Marketing')
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->label('Last updated')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('language')
                    ->options([
                        'en' => 'English',
                        'es' => 'Español',
                        'fr' => 'Français',
                        'pt' => 'Português',
                    ]),
                TernaryFilter::make('marketing_consent')
                    ->label('Marketing consent')
                    ->native(false),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->defaultSort('last_name')
            ->striped()
            ->emptyStateHeading('No guests yet')
            ->emptyStateDescription('Guest profiles will become the shared source of service preferences and stay history.')
            ->emptyStateIcon('heroicon-o-user-group');
    }
}
