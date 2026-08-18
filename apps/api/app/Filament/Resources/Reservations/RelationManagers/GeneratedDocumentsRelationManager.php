<?php

namespace App\Filament\Resources\Reservations\RelationManagers;

use App\Enums\DocumentKind;
use App\Enums\ReservationStatus;
use App\Filament\Support\InnPresentation;
use App\Models\GeneratedDocument;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Documents\RequestDocumentGeneration;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class GeneratedDocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'generatedDocuments';

    protected static ?string $title = 'Documents';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table->headerActions([
            Action::make('generate_document')
                ->label('Generate document')->icon('heroicon-o-document-plus')
                ->visible(fn (): bool => in_array($this->reservation()->status, [ReservationStatus::Confirmed, ReservationStatus::CheckedIn, ReservationStatus::CheckedOut], true))
                ->schema([
                    Select::make('kind')->options([
                        DocumentKind::ReservationConfirmation->value => 'Reservation confirmation',
                        DocumentKind::Itinerary->value => 'Itinerary',
                        DocumentKind::FolioStatement->value => 'Folio statement',
                    ])->required(),
                    Select::make('locale')->options(['en' => 'English', 'es' => 'Español'])->default('en')->required(),
                ])
                ->action(function (array $data): void {
                    $reservation = $this->reservation();
                    app(RequestDocumentGeneration::class)->handle(User::query()->findOrFail(auth()->id()), $reservation, DocumentKind::from($data['kind']), $data['locale'], (string) str()->uuid());
                    Notification::make()->success()->title('Document generation queued')->send();
                }),
        ])->columns([
            TextColumn::make('created_at')->label('Generated')
                ->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone()),
            TextColumn::make('kind')->badge()->formatStateUsing(fn ($state): string => InnPresentation::label($state)),
            TextColumn::make('status')->badge()->formatStateUsing(fn ($state): string => InnPresentation::label($state))
                ->color(fn ($state): string => InnPresentation::statusColor($state)),
            TextColumn::make('template.name')->label('Template')->placeholder('No template'),
            TextColumn::make('guest.full_name')->label('Guest')->placeholder('No guest'),
            TextColumn::make('signed_at')->label('Signed')
                ->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->placeholder('Not signed'),
        ])->recordActions([
            Action::make('download')->label('Download')->authorize('download')->url(fn (GeneratedDocument $record): string => route('filament.admin.generated-documents.download', ['tenant' => Filament::getTenant(), 'generatedDocument' => $record])),
        ])->defaultSort('created_at', 'desc');
    }

    private function reservation(): Reservation
    {
        $record = $this->getOwnerRecord();
        if (! $record instanceof Reservation) {
            throw new \LogicException('Generated document actions require a reservation owner.');
        }

        return $record;
    }
}
