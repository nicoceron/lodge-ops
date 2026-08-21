<?php

namespace App\Filament\Resources\IntegrationEvents;

use App\Filament\Resources\IntegrationEvents\Pages\ManageIntegrationEvents;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\InnPresentation;
use App\Models\IntegrationEvent;
use App\Services\Integrations\IntegrationEventService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class IntegrationEventResource extends TenantResource
{
    protected static ?string $model = IntegrationEvent::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bolt';

    protected static string|\UnitEnum|null $navigationGroup = 'Templates & Integrations';

    protected static bool $canCreateRecords = false;

    protected static bool $canEditRecords = false;

    protected static bool $canDeleteRecords = false;

    protected static ?string $viewCapability = 'canManageConfiguration';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('received_at')->dateTime('M j, Y · H:i:s', timezone: InnPresentation::timezone())->sortable(),
            TextColumn::make('connection.name')->label('Connection'), TextColumn::make('event_type')->badge(),
            TextColumn::make('external_id')->copyable(), TextColumn::make('external_version')->label('Version'),
            TextColumn::make('disposition')->badge(), TextColumn::make('raw_checksum')->label('Raw SHA-256')->limit(18)->copyable(),
            TextColumn::make('last_error')->limit(60)->placeholder('—'),
        ])->recordActions([
            Action::make('replay')->authorize('update')->requiresConfirmation()
                ->visible(fn (IntegrationEvent $record): bool => ! in_array($record->disposition, ['processing', 'received'], true))
                ->form([Textarea::make('reason')->required()->minLength(3)->maxLength(500)])
                ->action(fn (IntegrationEvent $record, array $data) => app(IntegrationEventService::class)->replay($record, auth()->id(), $data['reason'])),
        ])->defaultSort('received_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageIntegrationEvents::route('/')];
    }
}
