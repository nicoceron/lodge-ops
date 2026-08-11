<?php

namespace App\Policies;

class ExchangeRatePolicy extends TenantResourcePolicy
{
    protected ?string $viewCapability = 'canViewFinance';

    protected string $writeCapability = 'canManageMoney';
}
