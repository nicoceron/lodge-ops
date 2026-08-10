<?php

namespace App\Policies;

use App\Models\TenantModel;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

abstract class TenantPolicy
{
    protected function canView(User $user, ?TenantModel $model = null): bool
    {
        $context = app(TenantContext::class);
        $membership = $context->membership();

        return $membership !== null
            && $membership->user_id === $user->id
            && $membership->is_active
            && ($model === null || $model->tenant_id === $context->id());
    }

    protected function canWrite(User $user, ?TenantModel $model = null): bool
    {
        return $this->canView($user, $model)
            && app(TenantContext::class)->membership()?->role->canWrite() === true;
    }

    protected function canManageMoney(User $user, ?TenantModel $model = null): bool
    {
        return $this->canView($user, $model)
            && app(TenantContext::class)->membership()?->role->canManageMoney() === true;
    }
}
