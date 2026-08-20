<?php

namespace App\Policies;

use App\Models\ProviderEvent;
use App\Models\User;

class ProviderEventPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canViewFinance($user);
    }

    public function view(User $user, ProviderEvent $event): bool
    {
        return $this->canViewFinance($user, $event);
    }
}
