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

    public function create(User $user): bool
    {
        return $this->canManageConfiguration($user);
    }

    public function update(User $user, Property $property): bool
    {
        return $this->canManageConfiguration($user, $property);
    }

    public function delete(User $user, Property $property): bool
    {
        return $this->canManageConfiguration($user, $property);
    }
}
