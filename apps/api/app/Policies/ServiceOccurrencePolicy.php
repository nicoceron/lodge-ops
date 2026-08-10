<?php

namespace App\Policies;

use App\Models\ServiceOccurrence;
use App\Models\User;

class ServiceOccurrencePolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, ServiceOccurrence $occurrence): bool
    {
        return $this->canView($user, $occurrence);
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
