<?php

namespace App\Policies;

use App\Models\CrmActivity;
use App\Models\User;

class CrmActivityPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, CrmActivity $activity): bool
    {
        return $this->canView($user, $activity);
    }

    public function create(User $user): bool
    {
        return $this->canManageSales($user);
    }

    public function update(User $user, CrmActivity $activity): bool
    {
        return $this->canManageSales($user, $activity);
    }
}
