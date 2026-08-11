<?php

namespace App\Policies;

class StockLocationPolicy extends TenantResourcePolicy
{
    protected ?string $viewCapability = 'canManageRetail';

    protected string $writeCapability = 'canManageRetail';
}
