<?php

namespace App\Policies;

use App\Models\RatePlan;
use App\Models\User;

class RatePlanPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageReservations($user);
    }

    public function view(User $user, RatePlan $record): bool
    {
        return $this->canManageReservations($user, $record);
    }

    public function create(User $user): bool
    {
        return $this->canManageConfiguration($user);
    }

    public function update(User $user, RatePlan $record): bool
    {
        return $this->canManageConfiguration($user, $record);
    }

    public function manageConfiguration(User $user, RatePlan $record): bool
    {
        return $this->canManageConfiguration($user, $record);
    }

    public function delete(User $user, RatePlan $record): bool
    {
        return $this->canManageConfiguration($user, $record);
    }
}
