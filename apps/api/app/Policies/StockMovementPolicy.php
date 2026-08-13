<?php

namespace App\Policies;

use App\Models\StockMovement;
use App\Models\User;

class StockMovementPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageConfiguration($user) || $this->canViewRetail($user);
    }

    public function view(User $user, StockMovement $movement): bool
    {
        return $this->canManageConfiguration($user, $movement) || $this->canViewRetail($user, $movement);
    }

    public function create(User $user): bool
    {
        return $this->canManageConfiguration($user) || $this->canManageRetail($user);
    }
}
