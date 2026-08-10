<?php

namespace App\Policies;

use App\Models\FolioLine;
use App\Models\User;

class FolioLinePolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageMoney($user);
    }

    public function view(User $user, FolioLine $line): bool
    {
        return $this->canManageMoney($user, $line);
    }

    public function create(User $user): bool
    {
        return $this->canManageMoney($user);
    }

    public function reverse(User $user, FolioLine $line): bool
    {
        return $this->canManageMoney($user, $line);
    }

    public function update(User $user, FolioLine $line): bool
    {
        return false;
    }

    public function delete(User $user, FolioLine $line): bool
    {
        return false;
    }
}
