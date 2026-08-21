<?php

namespace App\Filament\Resources\PaymentTerminals;

use App\Filament\Resources\PaymentTerminals\Pages\ManagePaymentTerminals;
use App\Filament\Resources\TenantResource;
use App\Models\PaymentTerminal;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PaymentTerminalResource extends TenantResource
{
    protected static ?string $model = PaymentTerminal::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-device-phone-mobile';

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 13;

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
            TextColumn::make('provider_terminal_id')->label('Provider terminal')->copyable(),
            TextColumn::make('operating_mode')->badge(),
            IconColumn::make('is_enabled')->boolean(),
            TextColumn::make('health_state')->badge(),
            TextColumn::make('last_successful_order_at')->since()->placeholder('Never'),
            TextColumn::make('last_error')->limit(60)->placeholder('—'),
        ])->filters([SelectFilter::make('operating_mode')->options(['PDV' => 'PDV', 'STANDALONE' => 'Standalone'])]);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePaymentTerminals::route('/')];
    }
}
