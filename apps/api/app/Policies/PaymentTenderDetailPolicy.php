<?php

namespace App\Policies;

use App\Models\PaymentTenderDetail;
use App\Models\User;

class PaymentTenderDetailPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canViewGuestMoney($user);
    }

    public function view(User $user, PaymentTenderDetail $detail): bool
    {
        return $this->canViewGuestMoney($user, $detail);
    }

    public function resolve(User $user, PaymentTenderDetail $detail): bool
    {
        return $this->canManageMoney($user, $detail);
    }
}
