<?php

namespace App\Filament\Resources\Properties\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class PropertiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->description(fn ($record): string => $record->code)
                    ->searchable(['name', 'code'])
                    ->sortable(),
                TextColumn::make('timezone')
                    ->icon('heroicon-m-clock')
                    ->searchable(),
                TextColumn::make('resources_count')
                    ->label('Resources')
                    ->counts('resources')
                    ->badge()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->label('Last updated')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Active properties')
                    ->native(false),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->defaultSort('name')
            ->striped()
            ->emptyStateHeading('No properties yet')
            ->emptyStateDescription('Create the first lodge or property in this workspace.')
            ->emptyStateIcon('heroicon-o-building-office-2');
    }
}
