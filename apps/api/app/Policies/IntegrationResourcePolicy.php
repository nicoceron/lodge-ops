<?php

namespace App\Policies;

use App\Models\TenantModel;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

abstract class IntegrationResourcePolicy extends TenantResourcePolicy
{
    public function view(User $user, TenantModel $model): bool
    {
        return parent::view($user, $model) && $this->matchesPropertyScope($model);
    }

    public function update(User $user, TenantModel $model): bool
    {
        return parent::update($user, $model) && $this->matchesPropertyScope($model);
    }

    public function delete(User $user, TenantModel $model): bool
    {
        return parent::delete($user, $model) && $this->matchesPropertyScope($model);
    }

    private function matchesPropertyScope(TenantModel $model): bool
    {
        $scope = app(TenantContext::class)->propertyScopeId();

        return $scope === null || $model->getAttribute('property_id') === $scope;
    }
}
