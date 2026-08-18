<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Models\Reservation;

final class MarkNoShow
{
    public function __construct(private readonly CloseReservationWithPolicy $workflow) {}

    public function handle(Reservation $reservation, string $reason, ?int $actorId): Reservation
    {
        return $this->workflow->handle($reservation, ReservationStatus::NoShow, $reason, $actorId);
    }
}
