<?php

namespace App\Policies;

use App\Models\TenantModel;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

abstract class TenantResourcePolicy extends TenantPolicy
{
    protected ?string $viewCapability = null;

    protected string $writeCapability = 'canWrite';

    protected string $deleteCapability = 'canManageConfiguration';

    public function viewAny(User $user): bool
    {
        return $this->allows($user, $this->viewCapability);
    }

    public function view(User $user, TenantModel $model): bool
    {
        return $this->allows($user, $this->viewCapability, $model);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, $this->writeCapability);
    }

    public function update(User $user, TenantModel $model): bool
    {
        return $this->allows($user, $this->writeCapability, $model);
    }

    public function delete(User $user, TenantModel $model): bool
    {
        return $this->allows($user, $this->deleteCapability, $model);
    }

    public function deleteAny(User $user): bool
    {
        return $this->allows($user, $this->deleteCapability);
    }

    private function allows(User $user, ?string $capability, ?TenantModel $model = null): bool
    {
        if (! $this->canView($user, $model)) {
            return false;
        }

        if ($capability === null) {
            return true;
        }

        $role = app(TenantContext::class)->membership()?->role;

        return $role !== null && method_exists($role, $capability) && $role->{$capability}();
    }
}
