<?php

namespace App\Filament\Resources\Programs\Tables;

use App\Models\Program;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ProgramsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('property.name')
                    ->label('Property')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('default_duration_minutes')
                    ->label('Duration')
                    ->suffix(' min')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('capacity')
                    ->alignCenter()
                    ->numeric()
                    ->sortable(),
                TextColumn::make('price_minor')
                    ->label('Price')
                    ->money(fn (Program $record): string => $record->currency, divideBy: 100)
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('property')
                    ->relationship('property', 'name')
                    ->searchable()
                    ->preload(),
                TernaryFilter::make('is_active')
                    ->label('Active programs')
                    ->native(false),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->defaultSort('name')
            ->striped()
            ->emptyStateHeading('No programs yet')
            ->emptyStateDescription('Add the experiences guests can book during their stay.')
            ->emptyStateIcon('heroicon-o-sparkles');
    }
}
