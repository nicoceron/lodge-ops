<?php

namespace App\Policies;

class OrganizationPolicy extends TenantResourcePolicy
{
    protected ?string $viewCapability = 'canManageSales';

    protected string $writeCapability = 'canManageSales';
}
