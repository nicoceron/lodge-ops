<?php

namespace App\Policies;

use App\Models\OperationalTask;
use App\Models\User;
use App\Services\OperationalTaskAccess;
use App\Support\Tenancy\TenantContext;

class OperationalTaskPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, OperationalTask $task): bool
    {
        return $this->canManageOperations($user, $task) && $this->canAccessTask($user, $task);
    }

    public function viewOperations(User $user): bool
    {
        return $this->canManageOperations($user);
    }

    public function create(User $user): bool
    {
        return $this->canScheduleOperations($user);
    }

    public function update(User $user, OperationalTask $task): bool
    {
        return $this->canManageOperations($user, $task) && $this->canAccessTask($user, $task);
    }

    public function delete(User $user, OperationalTask $task): bool
    {
        return $this->canScheduleOperations($user, $task);
    }

    private function canAccessTask(User $user, OperationalTask $task): bool
    {
        $role = app(TenantContext::class)->membership()?->role;

        return $role !== null && app(OperationalTaskAccess::class)->allows($user, $task, $role);
    }
}
