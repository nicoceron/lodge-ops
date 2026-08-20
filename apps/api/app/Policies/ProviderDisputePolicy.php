<?php

namespace App\Policies;

use App\Models\ProviderDispute;
use App\Models\User;

class ProviderDisputePolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canViewFinance($user);
    }

    public function view(User $user, ProviderDispute $dispute): bool
    {
        return $this->canViewFinance($user, $dispute);
    }

    public function resolve(User $user, ProviderDispute $dispute): bool
    {
        return $this->canManageMoney($user, $dispute);
    }
}
