<?php

namespace App\Filament\Resources\CatalogItems;

use App\Filament\Resources\CatalogItems\Pages\ManageCatalogItems;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\LodgeOpsPresentation;
use App\Models\CatalogItem;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CatalogItemResource extends TenantResource
{
    protected static ?string $model = CatalogItem::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static string|\UnitEnum|null $navigationGroup = 'Retail & Stock';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $viewCapability = 'canManageRetail';

    protected static string $writeCapability = 'canManageRetail';

    protected static bool $canDeleteRecords = false;

    public static function canSeeCosts(): bool
    {
        $role = app(TenantContext::class)->membership()?->role;

        return $role?->canManageMoney() === true || $role?->canManageConfiguration() === true;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Catalog item')->columns(2)->schema([
                TextInput::make('sku')->label('SKU')->required()->maxLength(80),
                TextInput::make('name')->required()->maxLength(160),
                Select::make('type')->options(['retail' => 'Retail', 'extra' => 'Extra', 'service' => 'Service'])->required()->default('retail'),
                TextInput::make('currency')->required()->length(3)->default(fn (): string => app(TenantContext::class)->tenant()->currency)->dehydrateStateUsing(fn (string $state): string => strtoupper($state)),
                TextInput::make('price_minor')->label('Selling price · minor units')->numeric()->minValue(0)->required(),
                TextInput::make('cost_minor')->label('Unit cost · minor units')->numeric()->minValue(0)->default(0)->visible(static::canSeeCosts(...)),
                Toggle::make('track_stock')->default(false),
                Toggle::make('is_active')->default(true),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Catalog item')->columns(2)->schema([
                TextEntry::make('sku')->label('SKU')->copyable(),
                TextEntry::make('name'),
                TextEntry::make('type')->badge()->formatStateUsing(LodgeOpsPresentation::label(...)),
                TextEntry::make('price_minor')->label('Selling price')->money(fn (CatalogItem $record): string => $record->currency, divideBy: 100),
                TextEntry::make('cost_minor')->label('Unit cost')->money(fn (CatalogItem $record): string => $record->currency, divideBy: 100)->visible(static::canSeeCosts(...)),
                IconEntry::make('track_stock')->boolean(),
                IconEntry::make('is_active')->boolean(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sku')->label('SKU')->searchable()->copyable(),
                TextColumn::make('name')->searchable()->sortable()->weight('medium'),
                TextColumn::make('type')->badge()->formatStateUsing(LodgeOpsPresentation::label(...)),
                TextColumn::make('price_minor')->label('Price')->money(fn (CatalogItem $record): string => $record->currency, divideBy: 100)->sortable(),
                TextColumn::make('cost_minor')->label('Cost')->money(fn (CatalogItem $record): string => $record->currency, divideBy: 100)->visible(static::canSeeCosts(...))->sortable(),
                IconColumn::make('track_stock')->label('Stock')->boolean(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([
                SelectFilter::make('type')->options(['retail' => 'Retail', 'extra' => 'Extra', 'service' => 'Service']),
                TernaryFilter::make('track_stock'),
                TernaryFilter::make('is_active'),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->defaultSort('name')
            ->emptyStateHeading('The catalog is empty');
    }

    public static function getPages(): array
    {
        return ['index' => ManageCatalogItems::route('/')];
    }
}
