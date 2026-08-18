<?php

namespace App\Filament\Resources\CancellationPolicies;

use App\Filament\Resources\CancellationPolicies\Pages\ManageCancellationPolicies;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\InnPresentation;
use App\Models\CancellationPolicy;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CancellationPolicyResource extends TenantResource
{
    protected static ?string $model = CancellationPolicy::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static string|\UnitEnum|null $navigationGroup = 'Setup';

    protected static ?string $viewCapability = 'canManageReservations';

    protected static string $writeCapability = 'canManageConfiguration';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Cancellation policy')->columns(2)->schema([
                Select::make('property_id')->options(InnPresentation::propertyOptions(...))->required(), TextInput::make('name')->required()->maxLength(160),
                Textarea::make('summary')->rows(3)->columnSpanFull(), Toggle::make('is_default'), Toggle::make('is_active')->default(true)->required(),
            ]),
            Section::make('Fee tiers')->description('For a cancellation within the cutoff, retain the configured share or minimum fee.')->schema([
                Repeater::make('tiers')->relationship()->defaultItems(1)->columns(3)->schema([
                    TextInput::make('days_before_arrival')->label('Cutoff days')->integer()->minValue(0)->required(),
                    TextInput::make('retained_basis_points')->label('Retained basis points')->helperText('10000 = 100%.')->integer()->minValue(0)->maxValue(10000)->required(),
                    TextInput::make('minimum_fee_minor')->label('Minimum fee (minor units)')->integer()->minValue(0)->default(0)->required(),
                    TextInput::make('sort_order')->integer()->minValue(0)->default(0),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable(), TextColumn::make('property.name'), TextColumn::make('tiers_count')->counts('tiers')->label('Tiers'),
            IconColumn::make('is_default')->boolean(), IconColumn::make('is_active')->boolean(),
        ])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCancellationPolicies::route('/')];
    }
}
