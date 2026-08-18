<?php

namespace App\Filament\Resources\AutomationRules\Tables;

use App\Filament\Support\InnPresentation;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class AutomationRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('trigger')
                    ->badge()
                    ->formatStateUsing(InnPresentation::label(...))
                    ->color('info')
                    ->searchable(),
                IconColumn::make('is_active')
                    ->label('Enabled')
                    ->boolean(),
                TextColumn::make('last_ran_at')
                    ->label('Last run')
                    ->since()
                    ->placeholder('Never')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('trigger')
                    ->options(InnPresentation::automationTriggerOptions()),
                TernaryFilter::make('is_active')
                    ->label('Enabled rules')
                    ->native(false),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->defaultSort('name')
            ->striped()
            ->emptyStateHeading('No automations configured')
            ->emptyStateDescription('Create a rule to turn reservation events into consistent follow-through.')
            ->emptyStateIcon('heroicon-o-bolt');
    }
}
