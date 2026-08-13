<?php

namespace App\Policies;

use App\Models\ResourceCategory;
use App\Models\User;

class ResourceCategoryPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, ResourceCategory $category): bool
    {
        return $this->canView($user, $category);
    }

    public function create(User $user): bool
    {
        return $this->canManageConfiguration($user);
    }

    public function update(User $user, ResourceCategory $category): bool
    {
        return $this->canManageConfiguration($user, $category);
    }

    public function delete(User $user, ResourceCategory $category): bool
    {
        return $this->canManageConfiguration($user, $category);
    }
}
