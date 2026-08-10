<?php

namespace App\Filament\Resources\StockLocations\RelationManagers;

use App\Filament\Resources\CatalogItems\CatalogItemResource;
use App\Filament\Support\LodgeOpsPresentation;
use App\Models\StockMovement;
use Filament\Actions\CreateAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'movements';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('catalog_item_id')->label('Item')->relationship('item', 'name')->required()->searchable()->preload(),
            TextInput::make('quantity')->label('Quantity received')->numeric()->minValue(0.001)->required(),
            TextInput::make('unit_cost_minor')->label('Unit cost · minor units')->numeric()->minValue(0)->default(0)->visible(CatalogItemResource::canSeeCosts(...)),
            TextInput::make('reference')->required()->maxLength(160)->unique(ignoreRecord: true),
            DateTimePicker::make('occurred_at')->label('Occurred')->required()->default(now())->seconds(false),
        ]);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Immutable stock movement')->columns(2)->schema([
                TextEntry::make('item.name')->label('Item'),
                TextEntry::make('type')->badge()->formatStateUsing(LodgeOpsPresentation::label(...)),
                TextEntry::make('quantity')->numeric(decimalPlaces: 3),
                TextEntry::make('unit_cost_minor')->label('Unit cost')->money(fn (StockMovement $record): string => $record->item->currency, divideBy: 100)->visible(CatalogItemResource::canSeeCosts(...)),
                TextEntry::make('reference')->copyable(),
                TextEntry::make('occurred_at')->dateTime('M j, Y · H:i', timezone: LodgeOpsPresentation::timezone()),
                TextEntry::make('sale.reference')->label('Sale')->placeholder('—'),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_at')->label('Occurred')->dateTime('M j, Y · H:i', timezone: LodgeOpsPresentation::timezone())->sortable(),
                TextColumn::make('item.name')->label('Item')->searchable()->weight('medium'),
                TextColumn::make('type')->badge()->formatStateUsing(LodgeOpsPresentation::label(...)),
                TextColumn::make('quantity')->numeric(decimalPlaces: 3)->sortable(),
                TextColumn::make('reference')->copyable()->searchable(),
                TextColumn::make('sale.reference')->label('Sale')->placeholder('—'),
            ])
            ->filters([SelectFilter::make('type')->options(['receipt' => 'Receipt', 'sale' => 'Sale', 'adjustment' => 'Adjustment'])])
            ->headerActions([
                CreateAction::make('receive')->label('Receive stock')->icon('heroicon-o-arrow-down-tray')->mutateDataUsing(fn (array $data): array => [...$data, 'type' => 'receipt', 'unit_cost_minor' => $data['unit_cost_minor'] ?? 0]),
            ])
            ->recordActions([ViewAction::make()])
            ->defaultSort('occurred_at', 'desc')
            ->emptyStateHeading('No stock movements');
    }
}
