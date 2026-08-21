<?php

namespace App\Policies;

use App\Models\PaymentTerminal;
use App\Models\User;

class PaymentTerminalPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canViewGuestMoney($user);
    }

    public function view(User $user, PaymentTerminal $terminal): bool
    {
        return $this->canViewGuestMoney($user, $terminal);
    }

    public function create(User $user): bool
    {
        return $this->canManageMoney($user);
    }

    public function update(User $user, PaymentTerminal $terminal): bool
    {
        return $this->canManageMoney($user, $terminal);
    }
}
