<?php

namespace App\Filament\Resources\DeliveryAttempts;

use App\Filament\Resources\DeliveryAttempts\Pages\ManageDeliveryAttempts;
use App\Filament\Resources\TenantResource;
use App\Models\DeliveryAttempt;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DeliveryAttemptResource extends TenantResource
{
    protected static ?string $model = DeliveryAttempt::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-paper-airplane';

    protected static string|\UnitEnum|null $navigationGroup = 'Templates & Integrations';

    protected static ?int $navigationSort = 26;

    protected static bool $canCreateRecords = false;

    protected static bool $canEditRecords = false;

    protected static bool $canDeleteRecords = false;

    protected static ?string $propertyRelationship = 'communication';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('status')->badge(),
            TextEntry::make('kind')->badge(),
            TextEntry::make('provider'),
            TextEntry::make('provider_message_id')->copyable()->placeholder('Not known'),
            TextEntry::make('idempotency_key')->copyable(),
            TextEntry::make('request_checksum')->copyable(),
            TextEntry::make('safe_error')->placeholder('None'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('attempted_at')->since()->sortable(), TextColumn::make('provider')->badge(),
            TextColumn::make('status')->badge()->sortable(), TextColumn::make('kind')->badge(),
            TextColumn::make('provider_message_id')->limit(24)->copyable()->placeholder('—'),
            TextColumn::make('safe_error')->limit(40)->placeholder('—'),
        ])->filters([SelectFilter::make('status')->options([
            'failed' => 'Failed', 'retry_pending' => 'Retry pending', 'outcome_uncertain' => 'Outcome uncertain',
            'reconciliation_required' => 'Reconciliation required', 'provider_accepted' => 'Provider accepted', 'delivered' => 'Delivered',
        ])])->recordActions([ViewAction::make()])->defaultSort('attempted_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageDeliveryAttempts::route('/')];
    }
}
