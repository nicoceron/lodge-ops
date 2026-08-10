<?php

namespace App\Policies;

use App\Models\Deposit;
use App\Models\User;

class DepositPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageMoney($user);
    }

    public function view(User $user, Deposit $deposit): bool
    {
        return $this->canManageMoney($user, $deposit);
    }

    public function create(User $user): bool
    {
        return $this->canManageMoney($user);
    }

    public function update(User $user, Deposit $deposit): bool
    {
        return $this->canManageMoney($user, $deposit);
    }

    public function waive(User $user, Deposit $deposit): bool
    {
        return $this->canManageMoney($user, $deposit);
    }
}
