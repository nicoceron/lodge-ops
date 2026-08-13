<?php

namespace App\Policies;

use App\Models\Program;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

class ProgramPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user)
            && app(TenantContext::class)->membership()?->role->canViewPrograms() === true;
    }

    public function view(User $user, Program $program): bool
    {
        return $this->viewAny($user) && $this->canView($user, $program);
    }

    public function create(User $user): bool
    {
        return $this->canManageConfiguration($user);
    }

    public function update(User $user, Program $program): bool
    {
        return $this->canManageConfiguration($user, $program);
    }

    public function delete(User $user, Program $program): bool
    {
        return $this->canManageConfiguration($user, $program);
    }
}
