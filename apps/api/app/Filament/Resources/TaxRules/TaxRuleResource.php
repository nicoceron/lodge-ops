<?php

namespace App\Filament\Resources\TaxRules;

use App\Filament\Resources\TaxRules\Pages\ManageTaxRules;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\InnPresentation;
use App\Models\TaxRule;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TaxRuleResource extends TenantResource
{
    protected static ?string $model = TaxRule::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static string|\UnitEnum|null $navigationGroup = 'Setup';

    protected static ?string $viewCapability = 'canManageReservations';

    protected static string $writeCapability = 'canManageConfiguration';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Section::make('Tax rule')->columns(2)->schema([
            Select::make('property_id')->options(InnPresentation::propertyOptions(...))->required(), TextInput::make('name')->required(),
            Select::make('calculation_type')->options(['percentage' => 'Percentage', 'fixed' => 'Fixed amount'])->default('percentage')->required(),
            TextInput::make('percentage_basis_points')->label('Basis points')->integer()->minValue(0)->maxValue(10000),
            TextInput::make('fixed_amount_minor')->label('Fixed amount (minor units)')->integer()->minValue(0), Toggle::make('is_inclusive'),
            DatePicker::make('active_from'), DatePicker::make('active_until')->afterOrEqual('active_from'),
            TextInput::make('priority')->integer()->default(0), Toggle::make('is_active')->default(true)->required(),
        ])]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable(), TextColumn::make('property.name'), TextColumn::make('calculation_type')->badge(),
            TextColumn::make('percentage_basis_points')->label('Basis points')->placeholder('—'), IconColumn::make('is_inclusive')->boolean(), IconColumn::make('is_active')->boolean(),
        ])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageTaxRules::route('/')];
    }
}
