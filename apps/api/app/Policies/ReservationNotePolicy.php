<?php

namespace App\Policies;

use App\Models\ReservationNote;
use App\Models\User;

class ReservationNotePolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageReservations($user);
    }

    public function view(User $user, ReservationNote $note): bool
    {
        return $this->canManageReservations($user, $note);
    }

    public function create(User $user): bool
    {
        return $this->canManageReservations($user);
    }

    public function update(User $user, ReservationNote $note): bool
    {
        return false;
    }

    public function delete(User $user, ReservationNote $note): bool
    {
        return false;
    }
}
