<?php

namespace App\Filament\Resources\Resources\Tables;

use App\Enums\ResourceType;
use App\Filament\Support\LodgeOpsPresentation;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ResourcesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->description(fn ($record): string => $record->code)
                    ->searchable(['name', 'code'])
                    ->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(LodgeOpsPresentation::label(...))
                    ->color(fn ($state): string => match ($state instanceof ResourceType ? $state : ResourceType::tryFrom((string) $state)) {
                        ResourceType::Room => 'success',
                        ResourceType::Guide, ResourceType::Staff => 'info',
                        ResourceType::Vehicle, ResourceType::Boat => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('property.name')
                    ->label('Property')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('capacity')
                    ->alignCenter()
                    ->numeric()
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
                SelectFilter::make('type')
                    ->options(LodgeOpsPresentation::enumOptions(ResourceType::cases()))
                    ->multiple(),
                TernaryFilter::make('is_active')
                    ->label('Active resources')
                    ->native(false),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->defaultSort('name')
            ->striped()
            ->emptyStateHeading('No resources configured')
            ->emptyStateDescription('Add rooms, guides, equipment or vehicles to make them bookable.')
            ->emptyStateIcon('heroicon-o-rectangle-group');
    }
}
