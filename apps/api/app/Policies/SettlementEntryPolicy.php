<?php

namespace App\Policies;

use App\Models\SettlementEntry;
use App\Models\User;

class SettlementEntryPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canViewFinance($user);
    }

    public function view(User $user, SettlementEntry $entry): bool
    {
        return $this->canViewFinance($user, $entry);
    }

    public function resolve(User $user, SettlementEntry $entry): bool
    {
        return $this->canManageMoney($user, $entry);
    }
}
