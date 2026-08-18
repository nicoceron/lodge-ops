<?php

namespace App\Policies;

use App\Models\ReservationChange;
use App\Models\User;

class ReservationChangePolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageReservations($user) || $this->canViewGuestMoney($user);
    }

    public function view(User $user, ReservationChange $change): bool
    {
        return $this->canManageReservations($user, $change) || $this->canViewGuestMoney($user, $change);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ReservationChange $change): bool
    {
        return false;
    }

    public function delete(User $user, ReservationChange $change): bool
    {
        return false;
    }
}
