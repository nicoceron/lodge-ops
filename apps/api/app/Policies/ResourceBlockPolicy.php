<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\ResourceBlock;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

class ResourceBlockPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, ResourceBlock $block): bool
    {
        return app(TenantContext::class)->membership()?->role === MembershipRole::Guide
            ? $this->canOwnGuideBlock($user, $block)
            : $this->canView($user, $block);
    }

    public function create(User $user): bool
    {
        return $this->canManageAvailability($user);
    }

    public function update(User $user, ResourceBlock $block): bool
    {
        return $this->canOwnGuideBlock($user, $block)
            || ($this->canManageAvailability($user, $block)
                && app(TenantContext::class)->membership()?->role !== MembershipRole::Guide);
    }

    public function delete(User $user, ResourceBlock $block): bool
    {
        return $this->update($user, $block);
    }

    private function canOwnGuideBlock(User $user, ResourceBlock $block): bool
    {
        return $this->canView($user, $block)
            && app(TenantContext::class)->membership()?->role === MembershipRole::Guide
            && $block->resource()->where('user_id', $user->id)->exists();
    }
}
