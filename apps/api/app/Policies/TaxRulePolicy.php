<?php

namespace App\Policies;

use App\Models\TaxRule;
use App\Models\User;

class TaxRulePolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageReservations($user);
    }

    public function view(User $user, TaxRule $record): bool
    {
        return $this->canManageReservations($user, $record);
    }

    public function create(User $user): bool
    {
        return $this->canManageConfiguration($user);
    }

    public function update(User $user, TaxRule $record): bool
    {
        return $this->canManageConfiguration($user, $record);
    }

    public function delete(User $user, TaxRule $record): bool
    {
        return $this->canManageConfiguration($user, $record);
    }
}
