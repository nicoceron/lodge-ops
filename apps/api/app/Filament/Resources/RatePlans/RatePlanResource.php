<?php

namespace App\Filament\Resources\RatePlans;

use App\Filament\Resources\RatePlans\Pages\ManageRatePlans;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\InnPresentation;
use App\Models\CancellationPolicy;
use App\Models\CatalogItem;
use App\Models\DepositPolicy;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\ResourceCategory;
use App\Services\RatePlanPublicationValidator;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
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
                TextInput::make('version')->integer()->minValue(1)->default(1)->required()->disabledOn('edit'),
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
                    TextInput::make('name')->default('Nightly rate')->required(),
                    Select::make('price_type')->options(['per_night' => 'Per night', 'per_person' => 'Per person / night'])->default('per_night')->required(),
                    TextInput::make('amount_minor')->label('Amount (minor units)')->integer()->minValue(0)->required(),
                    DatePicker::make('starts_on'), DatePicker::make('ends_on')->afterOrEqual('starts_on'),
                    TagsInput::make('weekdays')->helperText('ISO weekday numbers 1–7; empty means every day.'),
                    TextInput::make('minimum_stay')->integer()->minValue(1)->default(1)->required(),
                    TextInput::make('maximum_stay')->integer()->minValue(1), TextInput::make('priority')->integer()->default(0),
                    TextInput::make('minimum_advance_days')->integer()->minValue(0), TextInput::make('maximum_advance_days')->integer()->minValue(0),
                    TagsInput::make('allowed_arrival_days')->helperText('ISO weekday numbers 1–7; empty means every day.'),
                    TextInput::make('minimum_occupancy')->integer()->minValue(1), TextInput::make('maximum_occupancy')->integer()->minValue(1),
                    TextInput::make('adult_amount_minor')->integer()->minValue(0), TextInput::make('child_amount_minor')->integer()->minValue(0),
                    TextInput::make('infant_amount_minor')->integer()->minValue(0), TextInput::make('single_supplement_minor')->integer()->minValue(0)->default(0),
                    TextInput::make('length_of_stay_adjustment_basis_points')->integer()->minValue(-10000)->maxValue(100000)->default(0),
                    Toggle::make('closed_to_arrival'), Toggle::make('closed_to_departure'), Toggle::make('stop_sell'),
                    Toggle::make('blackout'), Toggle::make('buyout_only'),
                ]),
            ]),
            Section::make('Included and optional services')->schema([
                Repeater::make('services')->relationship()->columns(3)->schema([
                    Select::make('catalog_item_id')->options(fn (): array => CatalogItem::query()->where('is_active', true)->pluck('name', 'id')->all())->required(),
                    Select::make('selection_type')->options(['included' => 'Included', 'optional' => 'Optional add-on'])->default('optional')->required(),
                    Select::make('quantity_basis')->options(['per_stay' => 'Per stay', 'per_night' => 'Per night', 'per_person' => 'Per person', 'per_person_night' => 'Per person / night'])->default('per_stay')->required(),
                    TextInput::make('amount_minor')->integer()->minValue(0)->helperText('Blank uses the catalog price.'),
                    TextInput::make('default_quantity')->integer()->minValue(1)->default(1)->required(),
                    TextInput::make('maximum_quantity')->integer()->minValue(1)->default(1)->required(),
                    TextInput::make('version')->integer()->minValue(1)->default(1)->required(), Toggle::make('is_active')->default(true),
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
        ])->recordActions([
            EditAction::make()->visible(fn (RatePlan $record): bool => $record->state === 'draft'),
            Action::make('previewCalendar')->label('Preview calendar')->icon('heroicon-o-calendar-days')
                ->modalHeading(fn (RatePlan $record): string => "Rule calendar · {$record->name} v{$record->version}")
                ->modalDescription(fn (RatePlan $record): string => $record->rules->map(
                    fn (RateRule $rule): string => $rule->name.': '.($rule->starts_on?->toDateString() ?? 'open').' → '.
                        ($rule->ends_on?->toDateString() ?? 'open')." · priority {$rule->priority}",
                )->implode("\n"))
                ->modalSubmitActionLabel('Close')->action(fn (): null => null),
            Action::make('publish')->requiresConfirmation()->icon('heroicon-o-check-circle')
                ->visible(fn (RatePlan $record): bool => $record->state === 'draft')
                ->action(function (RatePlan $record): void {
                    app(RatePlanPublicationValidator::class)->validate($record);
                    $record->state = 'published';
                    $record->published_at = now();
                    $record->approved_by = auth()->id();
                    $record->save();
                    Notification::make()->title('Rate plan version published')->success()->send();
                }),
            Action::make('copyVersion')->label('Copy new version')->icon('heroicon-o-document-duplicate')
                ->action(function (RatePlan $record): void {
                    $copy = $record->replicate(['state', 'published_at', 'retired_at']);
                    $copy->version = $record->version + 1;
                    $copy->state = 'draft';
                    $copy->supersedes_id = $record->id;
                    $copy->approved_by = null;
                    $copy->save();
                    foreach ($record->rules as $rule) {
                        $ruleCopy = $rule->replicate();
                        $ruleCopy->rate_plan_id = $copy->id;
                        $ruleCopy->save();
                    }
                    foreach ($record->services as $service) {
                        $serviceCopy = $service->replicate();
                        $serviceCopy->rate_plan_id = $copy->id;
                        $serviceCopy->save();
                    }
                    Notification::make()->title('Draft rate plan version created')->success()->send();
                }),
            Action::make('retire')->color('danger')->requiresConfirmation()->visible(fn (RatePlan $record): bool => $record->state === 'published')
                ->action(fn (RatePlan $record) => $record->update(['state' => 'retired', 'retired_at' => now(), 'is_active' => false])),
            DeleteAction::make()->visible(fn (RatePlan $record): bool => $record->state === 'draft'),
        ])->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => ManageRatePlans::route('/')];
    }
}
