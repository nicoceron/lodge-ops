<?php

namespace App\Filament\Resources\Reservations\RelationManagers;

use App\Enums\DocumentKind;
use App\Filament\Support\InnPresentation;
use App\Models\ReservationChange;
use App\Models\User;
use App\Services\Documents\RequestDocumentGeneration;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ReservationChangesRelationManager extends RelationManager
{
    protected static string $relationship = 'changes';

    protected static ?string $title = 'Change ledger';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('occurred_at')->label('When')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone()),
            TextColumn::make('type')->state(fn (ReservationChange $record): string => InnPresentation::label($record->type)),
            TextColumn::make('status')->state(fn (ReservationChange $record): string => InnPresentation::label($record->status)),
            TextColumn::make('amount_minor')->label('Amount / delta')
                ->money(fn (ReservationChange $record): string => $record->currency ?? 'USD', divideBy: 100)->placeholder('—'),
            TextColumn::make('actor.name')->label('By')->placeholder('System'),
            TextColumn::make('reference')->placeholder('—')->copyable(),
            TextColumn::make('metadata.reason')->label('Reason')->wrap()->placeholder('—'),
        ])->recordActions([
            Action::make('generate_refund_receipt')->label('Generate refund receipt')->icon('heroicon-o-document-arrow-down')
                ->visible(fn (ReservationChange $record): bool => $record->type === 'refund_completed' && $record->status === 'completed')
                ->action(function (ReservationChange $record): void {
                    app(RequestDocumentGeneration::class)->handle(User::query()->findOrFail(auth()->id()), $record->reservation, DocumentKind::RefundReceipt, app()->getLocale(), (string) str()->uuid(), change: $record);
                    Notification::make()->success()->title('Refund receipt queued')->send();
                }),
        ])->defaultSort('occurred_at', 'desc');
    }
}
