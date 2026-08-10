<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageMoney($user);
    }

    public function view(User $user, Payment $payment): bool
    {
        return $this->canManageMoney($user, $payment);
    }

    public function viewFinance(User $user): bool
    {
        return $this->canManageMoney($user);
    }

    public function create(User $user): bool
    {
        return $this->canManageMoney($user);
    }

    public function reconcile(User $user, Payment $payment): bool
    {
        return $this->canManageMoney($user, $payment);
    }

    public function reverse(User $user, Payment $payment): bool
    {
        return $this->canManageMoney($user, $payment);
    }
}
