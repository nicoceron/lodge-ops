<?php

namespace App\Policies;

use App\Models\RetailSaleLine;
use App\Models\User;

class RetailSaleLinePolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canViewRetail($user);
    }

    public function view(User $user, RetailSaleLine $line): bool
    {
        return $this->canViewRetail($user, $line);
    }
}
