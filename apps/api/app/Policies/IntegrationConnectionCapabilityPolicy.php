<?php

namespace App\Policies;

use App\Models\IntegrationConnectionCapability;
use App\Models\TenantModel;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

class IntegrationConnectionCapabilityPolicy extends TenantResourcePolicy
{
    protected ?string $viewCapability = 'canManageConfiguration';

    public function view(User $user, TenantModel $model): bool
    {
        if (! parent::view($user, $model) || ! $model instanceof IntegrationConnectionCapability) {
            return false;
        }
        $scope = app(TenantContext::class)->propertyScopeId();
        $connectionProperty = $model->connection()->value('property_id');

        return $scope === null || $connectionProperty === null || $connectionProperty === $scope;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, TenantModel $model): bool
    {
        return false;
    }

    public function delete(User $user, TenantModel $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
