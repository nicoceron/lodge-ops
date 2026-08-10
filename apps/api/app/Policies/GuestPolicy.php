<?php

namespace App\Policies;

use App\Models\Guest;
use App\Models\User;

class GuestPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, Guest $guest): bool
    {
        return $this->canView($user, $guest);
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user);
    }

    public function update(User $user, Guest $guest): bool
    {
        return $this->canWrite($user, $guest);
    }

    public function delete(User $user, Guest $guest): bool
    {
        return $this->canWrite($user, $guest);
    }
}
