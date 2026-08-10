<?php

namespace App\Filament\Resources\RetailSales\RelationManagers;

use App\Models\RetailSaleLine;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Posted sale line')->columns(2)->schema([
                TextEntry::make('item.name')->label('Item'),
                TextEntry::make('quantity')->numeric(decimalPlaces: 3),
                TextEntry::make('unit_amount_minor')->label('Unit price')->money(fn (RetailSaleLine $record): string => $record->sale->currency, divideBy: 100),
                TextEntry::make('amount_minor')->label('Line total')->money(fn (RetailSaleLine $record): string => $record->sale->currency, divideBy: 100),
                TextEntry::make('folioLine.id')->label('Folio line')->copyable()->placeholder('Walk-in sale'),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('item.sku')->label('SKU')->copyable(),
                TextColumn::make('item.name')->label('Item')->searchable()->weight('medium'),
                TextColumn::make('quantity')->numeric(decimalPlaces: 3),
                TextColumn::make('unit_amount_minor')->label('Unit price')->money(fn (RetailSaleLine $record): string => $record->sale->currency, divideBy: 100),
                TextColumn::make('amount_minor')->label('Total')->money(fn (RetailSaleLine $record): string => $record->sale->currency, divideBy: 100),
            ])
            ->recordActions([ViewAction::make()]);
    }
}
