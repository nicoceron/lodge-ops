<?php

namespace App\Services;

use App\Enums\AllocationStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\InvalidStatusTransitionException;
use App\Models\Reservation;
use App\Services\Automation\OutboxRecorder;
use Illuminate\Support\Facades\DB;

class ReservationService
{
    public function __construct(
        private AvailabilityService $availability,
        private OutboxRecorder $outbox,
    ) {}

    public function confirm(Reservation $reservation): Reservation
    {
        return DB::transaction(function () use ($reservation): Reservation {
            $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);

            if ($locked->status === ReservationStatus::Confirmed) {
                return $locked->load(['allocations.resource', 'primaryGuest']);
            }

            if (! $locked->status->canTransitionTo(ReservationStatus::Confirmed)) {
                throw new InvalidStatusTransitionException($locked->status, ReservationStatus::Confirmed);
            }

            $allocations = $locked->allocations()->lockForUpdate()->get();

            foreach ($allocations as $allocation) {
                $this->availability->assertAvailable($allocation);
            }

            foreach ($allocations as $allocation) {
                $allocation->update(['status' => AllocationStatus::Confirmed]);
            }

            $locked->update([
                'status' => ReservationStatus::Confirmed,
                'confirmed_at' => now(),
                'revision' => $locked->revision + 1,
            ]);

            $this->outbox->record(
                'reservation',
                $locked->id,
                'reservation.confirmed',
                ['reservation_id' => $locked->id, 'confirmation_number' => $locked->confirmation_number],
            );

            return $locked->fresh(['allocations.resource', 'primaryGuest']);
        }, 3);
    }

    public function transition(Reservation $reservation, ReservationStatus $next): Reservation
    {
        if ($next === ReservationStatus::Confirmed) {
            return $this->confirm($reservation);
        }

        return DB::transaction(function () use ($reservation, $next): Reservation {
            $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);

            if ($locked->status === $next) {
                return $locked;
            }

            if (! $locked->status->canTransitionTo($next)) {
                throw new InvalidStatusTransitionException($locked->status, $next);
            }

            $locked->update([
                'status' => $next,
                'revision' => $locked->revision + 1,
            ]);

            if ($next === ReservationStatus::Cancelled) {
                $locked->allocations()->update(['status' => AllocationStatus::Released]);
            }

            $this->outbox->record(
                'reservation',
                $locked->id,
                'reservation.status_changed',
                ['reservation_id' => $locked->id, 'status' => $next->value],
            );

            return $locked->fresh(['allocations.resource', 'primaryGuest']);
        }, 3);
    }
}
