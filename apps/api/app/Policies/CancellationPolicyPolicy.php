<?php

namespace App\Policies;

use App\Models\CancellationPolicy;
use App\Models\User;

class CancellationPolicyPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageReservations($user);
    }

    public function view(User $user, CancellationPolicy $record): bool
    {
        return $this->canManageReservations($user, $record);
    }

    public function create(User $user): bool
    {
        return $this->canManageConfiguration($user);
    }

    public function update(User $user, CancellationPolicy $record): bool
    {
        return $this->canManageConfiguration($user, $record);
    }

    public function delete(User $user, CancellationPolicy $record): bool
    {
        return $this->canManageConfiguration($user, $record);
    }
}
