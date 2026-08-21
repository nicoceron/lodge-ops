<?php

namespace App\Filament\Resources\IntegrationDeadLetters;

use App\Filament\Resources\IntegrationDeadLetters\Pages\ManageIntegrationDeadLetters;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\InnPresentation;
use App\Models\IntegrationDeadLetter;
use App\Services\Integrations\IntegrationEventService;
use App\Services\Integrations\IntegrationRunService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class IntegrationDeadLetterResource extends TenantResource
{
    protected static ?string $model = IntegrationDeadLetter::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

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
            TextColumn::make('created_at')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->sortable(),
            TextColumn::make('connection.name')->label('Connection'), TextColumn::make('reason_code')->badge(),
            TextColumn::make('status')->badge(), TextColumn::make('replay_count')->label('Replays'), TextColumn::make('safe_error')->limit(80),
        ])->recordActions([
            Action::make('replay')->authorize('update')->requiresConfirmation()->visible(fn (IntegrationDeadLetter $record): bool => $record->status !== 'resolved')
                ->form([Textarea::make('reason')->required()->minLength(3)->maxLength(500)])
                ->action(function (IntegrationDeadLetter $record, array $data): void {
                    $record->item !== null
                        ? app(IntegrationRunService::class)->replay($record, auth()->id(), $data['reason'])
                        : app(IntegrationEventService::class)->replay($record->event()->firstOrFail(), auth()->id(), $data['reason']);
                }),
        ])->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageIntegrationDeadLetters::route('/')];
    }
}
