<?php

namespace App\Policies;

class CatalogItemPolicy extends TenantResourcePolicy
{
    protected ?string $viewCapability = 'canManageRetail';

    protected string $writeCapability = 'canManageRetail';
}
