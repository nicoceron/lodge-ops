<?php

namespace App\Filament\Resources\DepositPolicies;

use App\Filament\Resources\DepositPolicies\Pages\ManageDepositPolicies;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\InnPresentation;
use App\Models\DepositPolicy;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DepositPolicyResource extends TenantResource
{
    protected static ?string $model = DepositPolicy::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static string|\UnitEnum|null $navigationGroup = 'Setup';

    protected static ?string $viewCapability = 'canManageReservations';

    protected static string $writeCapability = 'canManageConfiguration';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Section::make('Deposit policy')->columns(2)->schema([
            Select::make('property_id')->options(InnPresentation::propertyOptions(...))->required(),
            TextInput::make('name')->required()->maxLength(160),
            Select::make('requirement_type')->options(['percentage' => 'Percentage', 'fixed' => 'Fixed amount'])->default('percentage')->required(),
            TextInput::make('percentage_basis_points')->label('Deposit basis points')->helperText('5000 = 50%.')->integer()->minValue(0)->maxValue(10000),
            TextInput::make('fixed_amount_minor')->label('Fixed amount (minor units)')->integer()->minValue(0),
            TextInput::make('deposit_due_offset_days')->label('Deposit due after booking')->suffix('days')->integer()->default(0)->required(),
            TextInput::make('balance_due_offset_days')->label('Balance due before arrival')->suffix('days')->integer()->minValue(0)->default(30)->required(),
            Toggle::make('confirmation_requires_payment'), Toggle::make('is_default'), Toggle::make('is_active')->default(true)->required(),
        ])]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable(), TextColumn::make('property.name'), TextColumn::make('requirement_type')->badge(),
            TextColumn::make('percentage_basis_points')->label('Basis points')->placeholder('Fixed'),
            TextColumn::make('balance_due_offset_days')->label('Balance lead')->suffix(' days'), IconColumn::make('is_default')->boolean(), IconColumn::make('is_active')->boolean(),
        ])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageDepositPolicies::route('/')];
    }
}
