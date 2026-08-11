<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\Guest;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

class GuestPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageGuests($user) || $this->isViewer($user);
    }

    public function view(User $user, Guest $guest): bool
    {
        return $this->canManageGuests($user, $guest) || $this->isViewer($user, $guest);
    }

    public function create(User $user): bool
    {
        return $this->canManageGuests($user);
    }

    public function update(User $user, Guest $guest): bool
    {
        return $this->canManageGuests($user, $guest);
    }

    public function delete(User $user, Guest $guest): bool
    {
        return $this->canManageGuests($user, $guest);
    }

    private function isViewer(User $user, ?Guest $guest = null): bool
    {
        return $this->canView($user, $guest)
            && app(TenantContext::class)->membership()?->role === MembershipRole::Viewer;
    }
}
