<?php

namespace App\Policies;

use App\Models\DepositPolicy;
use App\Models\User;

class DepositPolicyPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageReservations($user);
    }

    public function view(User $user, DepositPolicy $record): bool
    {
        return $this->canManageReservations($user, $record);
    }

    public function create(User $user): bool
    {
        return $this->canManageConfiguration($user);
    }

    public function update(User $user, DepositPolicy $record): bool
    {
        return $this->canManageConfiguration($user, $record);
    }

    public function delete(User $user, DepositPolicy $record): bool
    {
        return $this->canManageConfiguration($user, $record);
    }
}
