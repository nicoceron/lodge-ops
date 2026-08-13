<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\Resource;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

class ResourcePolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, Resource $resource): bool
    {
        return $this->canView($user, $resource)
            && (app(TenantContext::class)->membership()?->role !== MembershipRole::Guide
                || $resource->user_id === $user->id);
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

    public function updateHousekeeping(User $user, Resource $resource): bool
    {
        return $this->canView($user, $resource)
            && app(TenantContext::class)->membership()?->role?->canManageHousekeeping() === true;
    }
}
