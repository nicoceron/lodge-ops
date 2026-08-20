<?php

namespace App\Filament\Resources\CommercialPromotions;

use App\Filament\Resources\CommercialPromotions\Pages\ManageCommercialPromotions;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\InnPresentation;
use App\Models\CommercialPromotion;
use BackedEnum;
use Filament\Actions\Action;
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

class CommercialPromotionResource extends TenantResource
{
    protected static ?string $model = CommercialPromotion::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-ticket';

    protected static string|\UnitEnum|null $navigationGroup = 'Setup';

    protected static ?string $viewCapability = 'canManageReservations';

    protected static string $writeCapability = 'canManageConfiguration';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Section::make('Versioned promotion')->columns(2)->schema([
            Select::make('property_id')->options(InnPresentation::propertyOptions(...))->required(),
            TextInput::make('name')->required()->maxLength(160), TextInput::make('public_label')->required()->maxLength(160),
            TextInput::make('version')->integer()->minValue(1)->default(1)->required(),
            TextInput::make('currency')->length(3)->required()->dehydrateStateUsing(fn (string $state): string => strtoupper($state)),
            Select::make('discount_type')->options(['percentage' => 'Percentage', 'fixed' => 'Fixed amount'])->default('percentage')->required(),
            TextInput::make('percentage_basis_points')->integer()->minValue(1)->maxValue(10000),
            TextInput::make('fixed_amount_minor')->integer()->minValue(1), DatePicker::make('valid_from'), DatePicker::make('valid_until')->afterOrEqual('valid_from'),
            TextInput::make('usage_limit')->integer()->minValue(1), TextInput::make('per_guest_limit')->integer()->minValue(1),
            TextInput::make('per_session_limit')->integer()->minValue(1),
            TextInput::make('budget_minor')->integer()->minValue(1), TextInput::make('stacking_group')->maxLength(80),
            TextInput::make('priority')->integer()->default(0), Toggle::make('requires_code'), Toggle::make('exclusive'),
            Toggle::make('reinstate_on_cancel')->helperText('If enabled, cancellation releases the use while preserving append-only history.'),
        ])]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable(), TextColumn::make('version')->badge(), TextColumn::make('state')->badge(),
            TextColumn::make('currency')->badge(), TextColumn::make('public_label'), IconColumn::make('requires_code')->boolean(),
        ])->recordActions([
            EditAction::make()->visible(fn (CommercialPromotion $record): bool => $record->state === 'draft'),
            Action::make('publish')->icon('heroicon-o-check-circle')->requiresConfirmation()
                ->visible(fn (CommercialPromotion $record): bool => $record->state === 'draft')
                ->action(function (CommercialPromotion $record): void {
                    if ($record->approval_owner_id === null) {
                        $record->approval_owner_id = auth()->id();
                    }
                    $record->state = 'published';
                    $record->published_at = now();
                    $record->save();
                    Notification::make()->title('Promotion version published')->success()->send();
                }),
            Action::make('copyVersion')->label('Copy new version')->icon('heroicon-o-document-duplicate')
                ->action(function (CommercialPromotion $record): void {
                    $copy = $record->replicate(['state', 'published_at', 'retired_at']);
                    $copy->version = $record->version + 1;
                    $copy->state = 'draft';
                    $copy->supersedes_id = $record->id;
                    $copy->save();
                    Notification::make()->title('Draft promotion version created')->success()->send();
                }),
            Action::make('retire')->color('danger')->requiresConfirmation()
                ->visible(fn (CommercialPromotion $record): bool => $record->state === 'published')
                ->action(fn (CommercialPromotion $record) => $record->update(['state' => 'retired', 'retired_at' => now()])),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCommercialPromotions::route('/')];
    }
}
