<?php

namespace App\Filament\Resources\GeneratedDocuments;

use App\Enums\DocumentKind;
use App\Filament\Resources\GeneratedDocuments\Pages\ManageGeneratedDocuments;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\InnPresentation;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Services\Documents\QueueGeneratedDocumentEmail;
use App\Services\Documents\RequestDocumentGeneration;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class GeneratedDocumentResource extends TenantResource
{
    protected static ?string $model = GeneratedDocument::class;

    protected static ?string $propertyRelationship = 'reservation';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-check';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'kind';

    protected static ?string $viewCapability = null;

    protected static string $writeCapability = 'canManageConfiguration';

    protected static bool $canCreateRecords = false;

    protected static bool $canEditRecords = false;

    protected static bool $canDeleteRecords = false;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $role = app(TenantContext::class)->membership()?->role;
        if ($role?->canManageReservations() !== true && $role?->canViewGuestMoney() === true) {
            $query->whereIn('kind', ['folio_statement', 'payment_receipt', 'refund_receipt']);
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Immutable generated document')->description('The checksum and generation metadata provide a permanent audit record.')->columns(2)->schema([
                TextEntry::make('kind')->badge(),
                TextEntry::make('status')->badge()->formatStateUsing(fn ($state): string => InnPresentation::label($state))->color(fn ($state): string => InnPresentation::statusColor($state)),
                TextEntry::make('reservation.confirmation_number')->label('Reservation')->placeholder('—'),
                TextEntry::make('guest.email')->label('Guest')->placeholder('—'),
                TextEntry::make('template.name')->label('Template')->placeholder('Version unavailable'),
                TextEntry::make('generated_at')->label('Generated')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone()),
                TextEntry::make('template_version')->label('Template version'),
                TextEntry::make('locale'),
                TextEntry::make('file_name')->label('Download name'),
                TextEntry::make('size_bytes')->label('Size')->numeric(),
                TextEntry::make('checksum')->copyable()->columnSpanFull(),
                KeyValueEntry::make('metadata')->columnSpanFull()->placeholder('No metadata'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Generated')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->sortable(),
                TextColumn::make('kind')->badge()->searchable(),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state): string => InnPresentation::label($state))->color(fn ($state): string => InnPresentation::statusColor($state)),
                TextColumn::make('reservation.confirmation_number')->label('Reservation')->searchable()->placeholder('—'),
                TextColumn::make('guest.email')->label('Guest')->searchable()->placeholder('—'),
                TextColumn::make('template.name')->label('Template')->placeholder('—'),
                TextColumn::make('checksum')->limit(12)->copyable(),
            ])
            ->filters([
                SelectFilter::make('kind')->options(fn (): array => GeneratedDocument::query()->distinct()->pluck('kind', 'kind')->all()),
                SelectFilter::make('status')->options(fn (): array => GeneratedDocument::query()->distinct()->pluck('status', 'status')->mapWithKeys(fn (string $value): array => [$value => InnPresentation::label($value)])->all()),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('download')->label('Download')->icon('heroicon-o-arrow-down-tray')->authorize('download')->url(fn (GeneratedDocument $record): string => route('filament.admin.generated-documents.download', ['tenant' => Filament::getTenant(), 'generatedDocument' => $record])),
                Action::make('email')->label('Email')->icon('heroicon-o-envelope')->authorize('email')->requiresConfirmation()
                    ->action(function (GeneratedDocument $record): void {
                        app(QueueGeneratedDocumentEmail::class)->handle(User::query()->findOrFail(auth()->id()), $record, (string) str()->uuid());
                        Notification::make()->success()->title('Document email queued')->send();
                    }),
                Action::make('replace')->label('Regenerate replacement')->icon('heroicon-o-arrow-path')->authorize('email')
                    ->visible(fn (GeneratedDocument $record): bool => $record->kind !== DocumentKind::WaiverCopy->value && $record->reservation_id !== null)
                    ->requiresConfirmation()
                    ->action(function (GeneratedDocument $record): void {
                        $record->loadMissing(['reservation', 'payment', 'reservationChange']);
                        app(RequestDocumentGeneration::class)->handle(User::query()->findOrFail(auth()->id()), $record->reservation, DocumentKind::from($record->kind), $record->locale, (string) str()->uuid(), $record->payment, $record->reservationChange, null, $record);
                        Notification::make()->success()->title('Replacement document queued')->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No documents generated');
    }

    public static function getPages(): array
    {
        return ['index' => ManageGeneratedDocuments::route('/')];
    }
}
