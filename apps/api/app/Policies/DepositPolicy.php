<?php

namespace App\Policies;

use App\Models\Deposit;
use App\Models\User;

class DepositPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canViewGuestMoney($user);
    }

    public function view(User $user, Deposit $deposit): bool
    {
        return $this->canViewGuestMoney($user, $deposit);
    }

    public function create(User $user): bool
    {
        return $this->canManageGuestMoney($user);
    }

    public function update(User $user, Deposit $deposit): bool
    {
        return $this->canManageGuestMoney($user, $deposit);
    }

    public function waive(User $user, Deposit $deposit): bool
    {
        return $this->canManageGuestMoney($user, $deposit);
    }
}
