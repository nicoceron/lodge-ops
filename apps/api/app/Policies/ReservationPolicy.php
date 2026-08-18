<?php

namespace App\Policies;

use App\Models\Reservation;
use App\Models\User;

class ReservationPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, Reservation $reservation): bool
    {
        return $this->canManageReservations($user, $reservation);
    }

    public function viewDirectory(User $user): bool
    {
        return $this->canManageReservations($user);
    }

    public function create(User $user): bool
    {
        return $this->canManageReservations($user);
    }

    public function update(User $user, Reservation $reservation): bool
    {
        return $this->canManageReservations($user, $reservation);
    }

    public function transition(User $user, Reservation $reservation): bool
    {
        return $this->canManageReservations($user, $reservation);
    }

    public function reallocate(User $user, Reservation $reservation): bool
    {
        return $this->canManageReservations($user, $reservation)
            || $this->canScheduleOperations($user, $reservation);
    }

    public function requestRefund(User $user, Reservation $reservation): bool
    {
        return $this->canManageGuestMoney($user, $reservation);
    }

    public function completeRefund(User $user, Reservation $reservation): bool
    {
        return $this->canManageMoney($user, $reservation);
    }
}
