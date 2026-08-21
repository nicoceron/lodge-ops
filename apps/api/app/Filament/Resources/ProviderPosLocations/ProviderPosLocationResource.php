<?php

namespace App\Filament\Resources\ProviderPosLocations;

use App\Filament\Resources\ProviderPosLocations\Pages\ManageProviderPosLocations;
use App\Filament\Resources\TenantResource;
use App\Models\ProviderPosLocation;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProviderPosLocationResource extends TenantResource
{
    protected static ?string $model = ProviderPosLocation::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-qr-code';

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 14;

    protected static bool $canCreateRecords = false;

    protected static bool $canEditRecords = false;

    protected static bool $canDeleteRecords = false;

    protected static ?string $viewCapability = 'canViewGuestMoney';

    protected static string $writeCapability = 'canManageMoney';

    protected static ?string $propertyRelationship = 'property';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('display_name')->searchable(),
            TextColumn::make('property.name'),
            TextColumn::make('provider_store_id')->label('Store')->copyable(),
            TextColumn::make('external_pos_id')->label('External POS')->copyable(),
            TextColumn::make('qr_mode')->badge(),
            IconColumn::make('is_enabled')->boolean(),
            TextColumn::make('health_state')->badge(),
            TextColumn::make('last_successful_order_at')->since()->placeholder('Never'),
        ])->filters([SelectFilter::make('qr_mode')->options(['static' => 'Static', 'dynamic' => 'Dynamic', 'hybrid' => 'Hybrid'])]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageProviderPosLocations::route('/')];
    }
}
