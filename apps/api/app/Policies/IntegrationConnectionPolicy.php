<?php

namespace App\Policies;

class IntegrationConnectionPolicy extends TenantResourcePolicy
{
    protected ?string $viewCapability = 'canManageConfiguration';

    protected string $writeCapability = 'canManageConfiguration';
}
