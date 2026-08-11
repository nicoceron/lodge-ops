<?php

namespace App\Filament\Resources\StockLocations;

use App\Filament\Resources\StockLocations\Pages\CreateStockLocation;
use App\Filament\Resources\StockLocations\Pages\EditStockLocation;
use App\Filament\Resources\StockLocations\Pages\ListStockLocations;
use App\Filament\Resources\StockLocations\Pages\ViewStockLocation;
use App\Filament\Resources\StockLocations\RelationManagers\MovementsRelationManager;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\LodgeOpsPresentation;
use App\Models\StockLocation;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StockLocationResource extends TenantResource
{
    protected static ?string $model = StockLocation::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static string|\UnitEnum|null $navigationGroup = 'Retail & Stock';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $viewCapability = 'canManageRetail';

    protected static string $writeCapability = 'canManageRetail';

    protected static bool $canDeleteRecords = false;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withSum('movements', 'quantity');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Stock location')->columns(2)->schema([
                Select::make('property_id')->label('Property')->options(LodgeOpsPresentation::propertyOptions(...))->required()->searchable()->preload(),
                TextInput::make('name')->required()->maxLength(160),
                TextInput::make('code')->required()->maxLength(40)->alphaDash()->dehydrateStateUsing(fn (string $state): string => strtoupper($state)),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Stock location')->columns(2)->schema([
                TextEntry::make('name'),
                TextEntry::make('code')->copyable(),
                TextEntry::make('property.name')->label('Property'),
                TextEntry::make('movements_sum_quantity')->label('Net units moved')->numeric(decimalPlaces: 3),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('medium'),
                TextColumn::make('code')->badge()->searchable()->copyable(),
                TextColumn::make('property.name')->label('Property')->searchable()->sortable(),
                TextColumn::make('movements_sum_quantity')->label('Net units moved')->numeric(decimalPlaces: 3)->sortable(),
                TextColumn::make('updated_at')->label('Updated')->since()->sortable(),
            ])
            ->filters([SelectFilter::make('property_id')->label('Property')->options(LodgeOpsPresentation::propertyOptions())])
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->defaultSort('name')
            ->emptyStateHeading('No stock locations');
    }

    public static function getRelations(): array
    {
        return [MovementsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockLocations::route('/'),
            'create' => CreateStockLocation::route('/create'),
            'view' => ViewStockLocation::route('/{record}'),
            'edit' => EditStockLocation::route('/{record}/edit'),
        ];
    }
}
