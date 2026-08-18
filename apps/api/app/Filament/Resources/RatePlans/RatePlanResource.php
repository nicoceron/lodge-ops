<?php

namespace App\Filament\Resources\RatePlans;

use App\Filament\Resources\RatePlans\Pages\ManageRatePlans;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\InnPresentation;
use App\Models\CancellationPolicy;
use App\Models\DepositPolicy;
use App\Models\RatePlan;
use App\Models\ResourceCategory;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RatePlanResource extends TenantResource
{
    protected static ?string $model = RatePlan::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|\UnitEnum|null $navigationGroup = 'Setup';

    protected static ?int $navigationSort = 35;

    protected static ?string $viewCapability = 'canManageReservations';

    protected static string $writeCapability = 'canManageConfiguration';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Rate plan')->columns(2)->schema([
                Select::make('property_id')->options(InnPresentation::propertyOptions(...))->live()->required(),
                TextInput::make('name')->required()->maxLength(160),
                TextInput::make('currency')->required()->length(3)->dehydrateStateUsing(fn (string $state): string => strtoupper($state)),
                TextInput::make('source_scope')->maxLength(50),
                DatePicker::make('active_from'), DatePicker::make('active_until')->afterOrEqual('active_from'),
                TextInput::make('minimum_occupancy')->integer()->minValue(1)->default(1)->required(),
                TextInput::make('maximum_occupancy')->integer()->minValue(1),
                Select::make('deposit_policy_id')->label('Deposit policy')->options(fn (Get $get): array => DepositPolicy::query()->when($get('property_id'), fn ($q, $id) => $q->where('property_id', $id))->pluck('name', 'id')->all()),
                Select::make('cancellation_policy_id')->label('Cancellation policy')->options(fn (Get $get): array => CancellationPolicy::query()->when($get('property_id'), fn ($q, $id) => $q->where('property_id', $id))->pluck('name', 'id')->all()),
                TagsInput::make('inclusions')->columnSpanFull(), Toggle::make('is_active')->default(true)->required(),
            ]),
            Section::make('Nightly rules')->description('Highest priority matching rule prices each night.')->schema([
                Repeater::make('rules')->relationship()->defaultItems(1)->columns(3)->schema([
                    Select::make('resource_category_id')->label('Accommodation category')->options(fn (): array => ResourceCategory::query()->where('counts_as_stay', true)->where('is_active', true)->pluck('name', 'id')->all()),
                    Select::make('price_type')->options(['per_night' => 'Per night', 'per_person' => 'Per person / night'])->default('per_night')->required(),
                    TextInput::make('amount_minor')->label('Amount (minor units)')->integer()->minValue(0)->required(),
                    DatePicker::make('starts_on'), DatePicker::make('ends_on')->afterOrEqual('starts_on'),
                    TagsInput::make('weekdays')->helperText('ISO weekday numbers 1–7; empty means every day.'),
                    TextInput::make('minimum_stay')->integer()->minValue(1)->default(1)->required(),
                    TextInput::make('maximum_stay')->integer()->minValue(1), TextInput::make('priority')->integer()->default(0),
                    Toggle::make('closed_to_arrival'), Toggle::make('closed_to_departure'), Toggle::make('stop_sell'),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->weight('medium'), TextColumn::make('property.name')->label('Property'),
            TextColumn::make('currency')->badge(), TextColumn::make('rules_count')->counts('rules')->label('Rules'),
            TextColumn::make('depositPolicy.name')->label('Deposit')->placeholder('Property default'),
            IconColumn::make('is_active')->boolean(),
        ])->recordActions([EditAction::make(), DeleteAction::make()])->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => ManageRatePlans::route('/')];
    }
}
