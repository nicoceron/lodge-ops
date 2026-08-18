<?php

namespace App\Policies;

use App\Models\PaymentRequest;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

class PaymentRequestPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canViewGuestMoney($user);
    }

    public function view(User $user, PaymentRequest $request): bool
    {
        return $this->canViewGuestMoney($user, $request);
    }

    public function create(User $user): bool
    {
        $role = app(TenantContext::class)->membership()?->role;

        return $this->canView($user) && ($role?->canManageSales() === true || $role?->canManageMoney() === true);
    }

    public function update(User $user, PaymentRequest $request): bool
    {
        return $this->create($user) && $this->canView($user, $request);
    }
}
