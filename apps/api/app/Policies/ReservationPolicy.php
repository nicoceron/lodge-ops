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
}
