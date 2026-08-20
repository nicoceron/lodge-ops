<?php

namespace App\Filament\Resources\IntegrationReconciliations;

use App\Filament\Resources\IntegrationReconciliations\Pages\ManageIntegrationReconciliations;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\InnPresentation;
use App\Models\IntegrationReconciliation;
use App\Services\Integrations\IntegrationOperationRecorder;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class IntegrationReconciliationResource extends TenantResource
{
    protected static ?string $model = IntegrationReconciliation::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-scale';

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
            TextColumn::make('created_at')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone()), TextColumn::make('connection.name'),
            TextColumn::make('kind')->badge(), TextColumn::make('reason_code'), TextColumn::make('external_key')->placeholder('—'),
            TextColumn::make('local_key')->placeholder('—'), TextColumn::make('status')->badge(),
        ])->recordActions([
            Action::make('resolve')->authorize('update')->requiresConfirmation()->visible(fn (IntegrationReconciliation $record): bool => $record->status === 'open')
                ->form([Textarea::make('resolution')->required()->minLength(3)->maxLength(500)])
                ->action(function (IntegrationReconciliation $record, array $data): void {
                    $record->update(['status' => 'resolved', 'resolved_by' => auth()->id(), 'resolved_at' => now(), 'resolution' => $data['resolution']]);
                    IntegrationOperationRecorder::record($record->connection()->firstOrFail(), 'reconciliation_resolved', auth()->id(), $data['resolution'], ['reconciliation_id' => $record->id]);
                }),
        ])->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageIntegrationReconciliations::route('/')];
    }
}
