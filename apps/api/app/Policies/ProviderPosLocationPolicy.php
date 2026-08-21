<?php

namespace App\Policies;

use App\Models\ProviderPosLocation;
use App\Models\User;

class ProviderPosLocationPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canViewGuestMoney($user);
    }

    public function view(User $user, ProviderPosLocation $location): bool
    {
        return $this->canViewGuestMoney($user, $location);
    }

    public function create(User $user): bool
    {
        return $this->canManageMoney($user);
    }

    public function update(User $user, ProviderPosLocation $location): bool
    {
        return $this->canManageMoney($user, $location);
    }
}
