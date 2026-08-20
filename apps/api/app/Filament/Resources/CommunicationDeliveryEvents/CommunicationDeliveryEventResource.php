<?php

namespace App\Filament\Resources\CommunicationDeliveryEvents;

use App\Filament\Resources\CommunicationDeliveryEvents\Pages\ManageCommunicationDeliveryEvents;
use App\Filament\Resources\TenantResource;
use App\Models\CommunicationDeliveryEvent;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CommunicationDeliveryEventResource extends TenantResource
{
    protected static ?string $model = CommunicationDeliveryEvent::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-signal';

    protected static string|\UnitEnum|null $navigationGroup = 'Templates & Integrations';

    protected static ?int $navigationSort = 27;

    protected static bool $canCreateRecords = false;

    protected static bool $canEditRecords = false;

    protected static bool $canDeleteRecords = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('type')->badge(),
            TextEntry::make('processing_state')->badge(),
            TextEntry::make('provider_event_id')->copyable(),
            TextEntry::make('provider_message_id')->copyable()->placeholder('Unknown message'),
            TextEntry::make('raw_body_checksum')->copyable(),
            KeyValueEntry::make('normalized_payload'),
            TextEntry::make('processing_error')->placeholder('None'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('received_at')->since()->sortable(), TextColumn::make('type')->badge(),
            TextColumn::make('provider_message_id')->limit(24)->copyable()->placeholder('—'),
            TextColumn::make('processing_state')->badge()->sortable(), TextColumn::make('processing_error')->limit(36)->placeholder('—'),
        ])->filters([SelectFilter::make('processing_state')->options([
            'pending' => 'Pending', 'processed' => 'Processed', 'failed' => 'Failed', 'reconciliation_required' => 'Reconciliation required',
        ])])->recordActions([ViewAction::make()])->defaultSort('received_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageCommunicationDeliveryEvents::route('/')];
    }
}
