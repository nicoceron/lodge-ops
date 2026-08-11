<?php

namespace App\Policies;

class RetailSalePolicy extends TenantResourcePolicy
{
    protected ?string $viewCapability = 'canViewRetail';

    protected string $writeCapability = 'canManageRetail';
}
