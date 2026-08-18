<?php

namespace App\Policies;

use App\Models\PaymentAttempt;
use App\Models\User;

class PaymentAttemptPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canViewFinance($user);
    }

    public function view(User $user, PaymentAttempt $attempt): bool
    {
        return $this->canViewFinance($user, $attempt);
    }

    public function reconcile(User $user, PaymentAttempt $attempt): bool
    {
        return $this->canManageMoney($user, $attempt);
    }
}
