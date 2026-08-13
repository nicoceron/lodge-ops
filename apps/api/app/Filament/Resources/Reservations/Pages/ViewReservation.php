<?php

namespace App\Filament\Resources\Reservations\Pages;

use App\Enums\FolioStatus;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Filament\Resources\Reservations\ReservationWorkflowActions;
use App\Models\FolioLine;
use App\Models\Reservation;
use App\Services\FolioService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;

class ViewReservation extends ViewRecord
{
    protected static string $resource = ReservationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            Action::make('closeFolio')
                ->label('Close folio')
                ->icon('heroicon-o-lock-closed')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (Reservation $record): bool => $record->folio_status === FolioStatus::Open && Gate::allows('create', FolioLine::class))
                ->action(function (Reservation $record): void {
                    app(FolioService::class)->close($record, auth()->id());
                    Notification::make()->success()->title('Folio closed')->send();
                    $this->refreshFormData(['folio_status', 'folio_closed_at', 'folio_closed_by']);
                }),
            Action::make('reopenFolio')
                ->label('Reopen folio')
                ->icon('heroicon-o-lock-open')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn (Reservation $record): bool => $record->folio_status === FolioStatus::Closed && Gate::allows('create', FolioLine::class))
                ->action(function (Reservation $record): void {
                    app(FolioService::class)->reopen($record);
                    Notification::make()->success()->title('Folio reopened')->send();
                    $this->refreshFormData(['folio_status', 'folio_closed_at', 'folio_closed_by']);
                }),
            ...ReservationWorkflowActions::make(),
        ];
    }
}
