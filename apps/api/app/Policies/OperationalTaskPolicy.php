<?php

namespace App\Policies;

use App\Models\OperationalTask;
use App\Models\User;

class OperationalTaskPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, OperationalTask $task): bool
    {
        return $this->canView($user, $task);
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user);
    }

    public function update(User $user, OperationalTask $task): bool
    {
        return $this->canWrite($user, $task);
    }

    public function delete(User $user, OperationalTask $task): bool
    {
        return $this->canWrite($user, $task);
    }
}
