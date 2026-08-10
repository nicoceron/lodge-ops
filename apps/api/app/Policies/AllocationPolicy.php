<?php

namespace App\Policies;

use App\Models\Allocation;
use App\Models\User;

class AllocationPolicy extends TenantPolicy
{
    public function create(User $user): bool
    {
        return $this->canManageReservations($user) || $this->canScheduleOperations($user);
    }

    public function update(User $user, Allocation $allocation): bool
    {
        return $this->canManageReservations($user, $allocation) || $this->canScheduleOperations($user, $allocation);
    }

    public function delete(User $user, Allocation $allocation): bool
    {
        return $this->update($user, $allocation);
    }
}
