<?php

namespace App\Policies;

use App\Models\CrmActivity;
use App\Models\User;

class CrmActivityPolicy extends TenantPolicy
{
    public function create(User $user): bool
    {
        return $this->canManageSales($user);
    }

    public function update(User $user, CrmActivity $activity): bool
    {
        return $this->canManageSales($user, $activity);
    }
}
