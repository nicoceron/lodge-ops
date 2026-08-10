<?php

namespace App\Policies;

use App\Models\Property;
use App\Models\User;

class PropertyPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, Property $property): bool
    {
        return $this->canView($user, $property);
    }
}
