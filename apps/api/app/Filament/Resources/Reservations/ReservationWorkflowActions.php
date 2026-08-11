<?php

namespace App\Filament\Resources\Reservations;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Services\ReservationService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

final class ReservationWorkflowActions
{
    /** @return array<Action> */
    public static function make(): array
    {
        return [
            self::transition(
                name: 'confirm',
                label: 'Confirm',
                status: ReservationStatus::Confirmed,
                icon: 'heroicon-o-check-badge',
                color: 'success',
                confirmation: 'Confirmation validates every tentative allocation before committing the stay.',
            ),
            self::transition(
                name: 'check_in',
                label: 'Check in',
                status: ReservationStatus::CheckedIn,
                icon: 'heroicon-o-key',
                color: 'success',
            ),
            self::transition(
                name: 'check_out',
                label: 'Check out',
                status: ReservationStatus::CheckedOut,
                icon: 'heroicon-o-arrow-right-start-on-rectangle',
                color: 'info',
            ),
            self::transition(
                name: 'no_show',
                label: 'Mark no-show',
                status: ReservationStatus::NoShow,
                icon: 'heroicon-o-user-minus',
                color: 'warning',
                confirmation: 'This closes the stay as a no-show and cannot be undone from the console.',
            ),
            self::transition(
                name: 'cancel',
                label: 'Cancel',
                status: ReservationStatus::Cancelled,
                icon: 'heroicon-o-x-circle',
                color: 'danger',
                confirmation: 'Cancellation releases all active allocations and cannot be undone from the console.',
            ),
        ];
    }

    private static function transition(
        string $name,
        string $label,
        ReservationStatus $status,
        string $icon,
        string $color,
        ?string $confirmation = null,
    ): Action {
        $action = Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->authorize('transition')
            ->visible(fn (Reservation $record): bool => ReservationResource::canTransition($record, $status))
            ->action(function (Reservation $record) use ($status, $label): void {
                app(ReservationService::class)->transition($record, $status);

                Notification::make()
                    ->success()
                    ->title("Reservation updated: {$label}")
                    ->send();
            });

        if ($confirmation !== null) {
            $action
                ->requiresConfirmation()
                ->modalDescription($confirmation);
        }

        return $action;
    }
}
