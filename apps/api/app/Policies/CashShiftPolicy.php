<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\CashShift;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

class CashShiftPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canViewGuestMoney($user);
    }

    public function view(User $user, CashShift $shift): bool
    {
        return $this->canViewGuestMoney($user, $shift);
    }

    public function create(User $user): bool
    {
        return $this->cashOperator($user);
    }

    public function operate(User $user, CashShift $shift): bool
    {
        return $this->canView($user, $shift) && $this->cashOperator($user);
    }

    public function approveVariance(User $user, CashShift $shift): bool
    {
        return $this->canManageMoney($user, $shift);
    }

    private function cashOperator(User $user): bool
    {
        return $this->canView($user)
            && in_array(app(TenantContext::class)->membership()?->role, [MembershipRole::Administrator, MembershipRole::Manager, MembershipRole::Operations], true);
    }
}
