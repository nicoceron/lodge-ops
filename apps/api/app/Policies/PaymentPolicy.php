<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, Payment $payment): bool
    {
        return $this->canView($user, $payment);
    }

    public function create(User $user): bool
    {
        return $this->canManageMoney($user);
    }
}
