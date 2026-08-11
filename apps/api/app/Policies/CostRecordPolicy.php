<?php

namespace App\Policies;

class CostRecordPolicy extends TenantResourcePolicy
{
    protected ?string $viewCapability = 'canViewFinance';

    protected string $writeCapability = 'canManageMoney';
}
