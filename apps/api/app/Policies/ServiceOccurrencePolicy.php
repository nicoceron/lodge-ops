<?php

namespace App\Policies;

use App\Enums\AllocationStatus;
use App\Enums\MembershipRole;
use App\Models\ServiceOccurrence;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

class ServiceOccurrencePolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user)
            && app(TenantContext::class)->membership()?->role->canViewServiceOccurrences() === true;
    }

    public function view(User $user, ServiceOccurrence $occurrence): bool
    {
        if (! $this->viewAny($user) || ! $this->canView($user, $occurrence)) {
            return false;
        }

        if (app(TenantContext::class)->membership()?->role !== MembershipRole::Guide) {
            return true;
        }

        return $occurrence->allocations()
            ->where('status', '!=', AllocationStatus::Released)
            ->whereHas('resource', fn ($query) => $query->where('user_id', $user->id))
            ->exists();
    }

    public function create(User $user): bool
    {
        return $this->canScheduleOperations($user);
    }

    public function update(User $user, ServiceOccurrence $occurrence): bool
    {
        return $this->canScheduleOperations($user, $occurrence);
    }

    public function delete(User $user, ServiceOccurrence $occurrence): bool
    {
        return $this->update($user, $occurrence);
    }
}
