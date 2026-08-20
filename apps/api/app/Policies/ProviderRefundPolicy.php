<?php

namespace App\Policies;

use App\Models\ProviderRefund;
use App\Models\User;

class ProviderRefundPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canViewFinance($user);
    }

    public function view(User $user, ProviderRefund $refund): bool
    {
        return $this->canViewFinance($user, $refund);
    }

    public function recover(User $user, ProviderRefund $refund): bool
    {
        return $this->canManageMoney($user, $refund);
    }
}
