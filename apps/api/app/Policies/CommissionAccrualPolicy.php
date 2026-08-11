<?php

namespace App\Policies;

use App\Models\CommissionAccrual;
use App\Models\User;

class CommissionAccrualPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canViewFinance($user);
    }

    public function view(User $user, CommissionAccrual $accrual): bool
    {
        return $this->canViewFinance($user, $accrual);
    }

    public function create(User $user): bool
    {
        return $this->canManageMoney($user);
    }

    public function update(User $user, CommissionAccrual $accrual): bool
    {
        return $this->canManageMoney($user, $accrual);
    }

    public function markPaid(User $user, CommissionAccrual $accrual): bool
    {
        return $this->update($user, $accrual);
    }
}
