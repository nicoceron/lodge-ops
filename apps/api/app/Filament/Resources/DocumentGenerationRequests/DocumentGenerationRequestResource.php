<?php

namespace App\Filament\Resources\DocumentGenerationRequests;

use App\Enums\DocumentGenerationStatus;
use App\Filament\Resources\DocumentGenerationRequests\Pages\ManageDocumentGenerationRequests;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\InnPresentation;
use App\Models\DocumentGenerationRequest;
use App\Models\User;
use App\Services\Documents\RetryDocumentGeneration;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DocumentGenerationRequestResource extends TenantResource
{
    protected static ?string $model = DocumentGenerationRequest::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 55;

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
            Section::make('Generation request')->columns(2)->schema([
                TextEntry::make('kind')->badge()->formatStateUsing(fn ($state): string => InnPresentation::label($state)),
                TextEntry::make('status')->badge()->formatStateUsing(fn ($state): string => InnPresentation::label($state))->color(fn ($state): string => InnPresentation::statusColor($state)),
                TextEntry::make('reservation.confirmation_number')->label('Reservation'),
                TextEntry::make('requestedBy.name')->label('Requested by'),
                TextEntry::make('attempts'),
                TextEntry::make('completed_at')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->placeholder('Not completed'),
                TextEntry::make('last_error')->label('Failure')->placeholder('None')->columnSpanFull(),
                TextEntry::make('source_checksum')->label('Source checksum')->copyable()->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('created_at')->label('Requested')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->sortable(),
            TextColumn::make('kind')->badge()->formatStateUsing(fn ($state): string => InnPresentation::label($state)),
            TextColumn::make('status')->badge()->formatStateUsing(fn ($state): string => InnPresentation::label($state))->color(fn ($state): string => InnPresentation::statusColor($state)),
            TextColumn::make('reservation.confirmation_number')->label('Reservation')->searchable(),
            TextColumn::make('requestedBy.name')->label('Requested by'),
            TextColumn::make('attempts'),
            TextColumn::make('last_error')->label('Failure')->limit(60)->placeholder('—'),
        ])->recordActions([
            ViewAction::make(),
            Action::make('retry')->label('Retry')->icon('heroicon-o-arrow-path')->authorize('retry')
                ->visible(fn (DocumentGenerationRequest $record): bool => $record->status === DocumentGenerationStatus::Failed)
                ->action(function (DocumentGenerationRequest $record): void {
                    app(RetryDocumentGeneration::class)->handle(User::query()->findOrFail(auth()->id()), $record);
                    Notification::make()->success()->title('Document generation retry queued')->send();
                }),
        ])->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageDocumentGenerationRequests::route('/')];
    }
}
