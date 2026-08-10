<?php

namespace App\Policies;

use App\Models\Program;
use App\Models\User;

class ProgramPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, Program $program): bool
    {
        return $this->canView($user, $program);
    }
}
