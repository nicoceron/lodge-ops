<?php

namespace App\Filament\Resources\TaxRules;

use App\Filament\Resources\TaxRules\Pages\ManageTaxRules;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\InnPresentation;
use App\Models\TaxRule;
use App\Services\CommercialVersionPublisher;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
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
            TextInput::make('version')->integer()->minValue(1)->default(1)->required(),
            Select::make('calculation_type')->options(['percentage' => 'Percentage', 'fixed' => 'Fixed amount'])->default('percentage')->required(),
            TextInput::make('currency')->label('Fixed-tax currency')->length(3)->dehydrateStateUsing(fn (?string $state): ?string => $state === null ? null : strtoupper($state)),
            TextInput::make('percentage_basis_points')->label('Basis points')->integer()->minValue(0)->maxValue(10000),
            TextInput::make('fixed_amount_minor')->label('Fixed amount (minor units)')->integer()->minValue(0), Toggle::make('is_inclusive'),
            DatePicker::make('active_from'), DatePicker::make('active_until')->afterOrEqual('active_from'),
            Select::make('taxable_discount_allocation')->options(['before_tax' => 'Discount before tax', 'after_tax' => 'Discount after tax'])->default('before_tax')->required(),
            Select::make('rounding_mode')->options(['half_up' => 'Half up', 'half_even' => 'Half even', 'up' => 'Up', 'down' => 'Down'])->default('half_up')->required(),
            Select::make('rounding_scope')->options(['total' => 'Quote total', 'line' => 'Each line'])->default('total')->required(),
            TextInput::make('priority')->integer()->default(0), Toggle::make('is_active')->default(true)->required(),
        ])]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable(), TextColumn::make('property.name'), TextColumn::make('calculation_type')->badge(),
            TextColumn::make('version')->badge(), TextColumn::make('state')->badge(),
            TextColumn::make('percentage_basis_points')->label('Basis points')->placeholder('—'), IconColumn::make('is_inclusive')->boolean(), IconColumn::make('is_active')->boolean(),
        ])->recordActions([
            EditAction::make()->visible(fn (TaxRule $record): bool => $record->state === 'draft'),
            Action::make('publish')->requiresConfirmation()->icon('heroicon-o-check-circle')
                ->authorize('manageConfiguration')
                ->visible(fn (TaxRule $record): bool => $record->state === 'draft')
                ->action(function (TaxRule $record): void {
                    app(CommercialVersionPublisher::class)->publishTaxRule($record, auth()->id());
                    Notification::make()->title('Tax-input version published')->success()->send();
                }),
            Action::make('copyVersion')->label('Copy new version')->icon('heroicon-o-document-duplicate')
                ->authorize('manageConfiguration')
                ->action(function (TaxRule $record): void {
                    $copy = $record->replicate(['state', 'published_at', 'retired_at']);
                    $copy->version = $record->version + 1;
                    $copy->state = 'draft';
                    $copy->supersedes_id = $record->id;
                    $copy->approved_by = null;
                    $copy->save();
                    Notification::make()->title('Draft tax-input version created')->success()->send();
                }),
            Action::make('retire')->color('danger')->requiresConfirmation()
                ->authorize('manageConfiguration')
                ->visible(fn (TaxRule $record): bool => $record->state === 'published')
                ->action(fn (TaxRule $record) => $record->update(['state' => 'retired', 'retired_at' => now(), 'is_active' => false])),
            DeleteAction::make()->visible(fn (TaxRule $record): bool => $record->state === 'draft'),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageTaxRules::route('/')];
    }
}
