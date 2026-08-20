<?php

namespace App\Policies;

use App\Models\IntegrationSyncRunItem;
use App\Models\TenantModel;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

class IntegrationSyncRunItemPolicy extends TenantResourcePolicy
{
    protected ?string $viewCapability = 'canManageConfiguration';

    public function view(User $user, TenantModel $model): bool
    {
        if (! parent::view($user, $model) || ! $model instanceof IntegrationSyncRunItem) {
            return false;
        }
        $scope = app(TenantContext::class)->propertyScopeId();
        $runProperty = $model->run()->value('property_id');

        return $scope === null || $runProperty === $scope;
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
