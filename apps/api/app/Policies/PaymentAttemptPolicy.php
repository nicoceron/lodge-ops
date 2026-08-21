<?php

namespace App\Policies;

use App\Models\PaymentAttempt;
use App\Models\User;

class PaymentAttemptPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canViewGuestMoney($user);
    }

    public function view(User $user, PaymentAttempt $attempt): bool
    {
        return $this->canViewGuestMoney($user, $attempt);
    }

    public function reconcile(User $user, PaymentAttempt $attempt): bool
    {
        return $this->canManageMoney($user, $attempt);
    }

    public function cancel(User $user, PaymentAttempt $attempt): bool
    {
        return $this->canManageGuestMoney($user, $attempt);
    }
}
