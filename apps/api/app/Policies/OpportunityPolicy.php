<?php

namespace App\Policies;

use App\Models\Opportunity;
use App\Models\User;

class OpportunityPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageSales($user);
    }

    public function view(User $user, Opportunity $opportunity): bool
    {
        return $this->canManageSales($user, $opportunity);
    }

    public function create(User $user): bool
    {
        return $this->canManageSales($user);
    }

    public function update(User $user, Opportunity $opportunity): bool
    {
        return $this->canManageSales($user, $opportunity);
    }
}
