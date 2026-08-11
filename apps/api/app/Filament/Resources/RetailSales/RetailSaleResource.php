<?php

namespace App\Filament\Resources\RetailSales;

use App\Filament\Resources\RetailSales\Pages\CreateRetailSale;
use App\Filament\Resources\RetailSales\Pages\ListRetailSales;
use App\Filament\Resources\RetailSales\Pages\ViewRetailSale;
use App\Filament\Resources\RetailSales\RelationManagers\LinesRelationManager;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\LodgeOpsPresentation;
use App\Models\CatalogItem;
use App\Models\RetailSale;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RetailSaleResource extends TenantResource
{
    protected static ?string $model = RetailSale::class;

    protected static ?string $propertyRelationship = 'stockLocation';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static string|\UnitEnum|null $navigationGroup = 'Retail & Stock';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'reference';

    protected static ?string $viewCapability = 'canViewRetail';

    protected static string $writeCapability = 'canManageRetail';

    protected static bool $canEditRecords = false;

    protected static bool $canDeleteRecords = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Post retail sale')->description('Posting is atomic: stock, sale lines, and any reservation folio charge are created together.')->columns(2)->schema([
                Select::make('stock_location_id')->label('Stock location')->options(LodgeOpsPresentation::stockLocationOptions(...))->required()->searchable()->preload(),
                Select::make('reservation_id')->label('Reservation folio · optional')->options(LodgeOpsPresentation::reservationOptions(...))->searchable()->preload(),
                TextInput::make('reference')->required()->maxLength(160)->unique(ignoreRecord: true),
                TextInput::make('tax_minor')->label('Tax · minor units')->numeric()->minValue(0)->default(0)->required(),
                Repeater::make('lines')->minItems(1)->defaultItems(1)->columns(2)->schema([
                    Select::make('catalog_item_id')->label('Catalog item')->options(fn (): array => CatalogItem::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())->required()->searchable(),
                    TextInput::make('quantity_milli')->label('Quantity · thousandths')->helperText('1000 = one unit; 1500 = 1.5 units')->integer()->minValue(1)->default(1000)->required(),
                ])->columnSpanFull(),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Immutable posted sale')->description('Corrections are posted as new transactions; this record never changes in place.')->columns(2)->schema([
                TextEntry::make('reference')->copyable()->weight('bold'),
                TextEntry::make('status')->badge()->formatStateUsing(LodgeOpsPresentation::label(...))->color('success'),
                TextEntry::make('stockLocation.name')->label('Stock location'),
                TextEntry::make('reservation.confirmation_number')->label('Reservation folio')->placeholder('Walk-in sale'),
                TextEntry::make('subtotal_minor')->label('Subtotal')->money(fn (RetailSale $record): string => $record->currency, divideBy: 100),
                TextEntry::make('tax_minor')->label('Tax')->money(fn (RetailSale $record): string => $record->currency, divideBy: 100),
                TextEntry::make('total_minor')->label('Total')->money(fn (RetailSale $record): string => $record->currency, divideBy: 100)->weight('bold'),
                TextEntry::make('posted_at')->label('Posted')->dateTime('M j, Y · H:i', timezone: LodgeOpsPresentation::timezone()),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('posted_at')->label('Posted')->dateTime('M j, Y · H:i', timezone: LodgeOpsPresentation::timezone())->sortable(),
                TextColumn::make('reference')->copyable()->searchable()->weight('medium'),
                TextColumn::make('stockLocation.name')->label('Location')->searchable(),
                TextColumn::make('reservation.confirmation_number')->label('Reservation')->searchable()->placeholder('Walk-in'),
                TextColumn::make('total_minor')->label('Total')->money(fn (RetailSale $record): string => $record->currency, divideBy: 100)->sortable(),
                TextColumn::make('status')->badge()->formatStateUsing(LodgeOpsPresentation::label(...))->color('success'),
            ])
            ->filters([
                SelectFilter::make('stock_location_id')->label('Location')->relationship('stockLocation', 'name'),
                SelectFilter::make('currency')->options(fn (): array => RetailSale::query()->distinct()->pluck('currency', 'currency')->all()),
            ])
            ->recordActions([ViewAction::make()])
            ->defaultSort('posted_at', 'desc')
            ->emptyStateHeading('No retail sales posted');
    }

    public static function getRelations(): array
    {
        return [LinesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRetailSales::route('/'),
            'create' => CreateRetailSale::route('/create'),
            'view' => ViewRetailSale::route('/{record}'),
        ];
    }
}
