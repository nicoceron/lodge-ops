<?php

namespace App\Policies;

use App\Models\Resource;
use App\Models\User;

class ResourcePolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, Resource $resource): bool
    {
        return $this->canView($user, $resource);
    }

    public function create(User $user): bool
    {
        return $this->canManageConfiguration($user);
    }

    public function update(User $user, Resource $resource): bool
    {
        return $this->canManageConfiguration($user, $resource);
    }

    public function delete(User $user, Resource $resource): bool
    {
        return $this->canManageConfiguration($user, $resource);
    }
}
