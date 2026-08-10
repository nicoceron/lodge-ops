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
            && ($model === null || $model->tenant_id === $context->id())
            && ($model === null
                || ! array_key_exists('property_id', $model->getAttributes())
                || $membership->property_id === null
                || $model->getAttribute('property_id') === $membership->property_id);
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

    protected function canManageGuestMoney(User $user, ?TenantModel $model = null): bool
    {
        return $this->canView($user, $model)
            && app(TenantContext::class)->membership()?->role->canManageGuestMoney() === true;
    }

    protected function canViewGuestMoney(User $user, ?TenantModel $model = null): bool
    {
        return $this->canView($user, $model)
            && app(TenantContext::class)->membership()?->role->canViewGuestMoney() === true;
    }

    protected function canManageReservations(User $user, ?TenantModel $model = null): bool
    {
        return $this->canView($user, $model)
            && app(TenantContext::class)->membership()?->role->canManageReservations() === true;
    }

    protected function canManageGuests(User $user, ?TenantModel $model = null): bool
    {
        return $this->canView($user, $model)
            && app(TenantContext::class)->membership()?->role->canManageGuests() === true;
    }

    protected function canManageOperations(User $user, ?TenantModel $model = null): bool
    {
        return $this->canView($user, $model)
            && app(TenantContext::class)->membership()?->role->canManageOperations() === true;
    }

    protected function canManageConfiguration(User $user, ?TenantModel $model = null): bool
    {
        return $this->canView($user, $model)
            && app(TenantContext::class)->membership()?->role->canManageConfiguration() === true;
    }

    protected function canManageAvailability(User $user, ?TenantModel $model = null): bool
    {
        return $this->canView($user, $model)
            && app(TenantContext::class)->membership()?->role->canManageAvailability() === true;
    }

    protected function canScheduleOperations(User $user, ?TenantModel $model = null): bool
    {
        return $this->canView($user, $model)
            && app(TenantContext::class)->membership()?->role->canScheduleOperations() === true;
    }
}
