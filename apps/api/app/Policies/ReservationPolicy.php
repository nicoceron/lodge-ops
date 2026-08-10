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
        return $this->canView($user, $reservation);
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user);
    }

    public function update(User $user, Reservation $reservation): bool
    {
        return $this->canWrite($user, $reservation);
    }

    public function transition(User $user, Reservation $reservation): bool
    {
        return $this->canWrite($user, $reservation);
    }
}
