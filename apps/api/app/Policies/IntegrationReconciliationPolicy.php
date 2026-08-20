<?php

namespace App\Policies;

class IntegrationReconciliationPolicy extends IntegrationResourcePolicy
{
    protected ?string $viewCapability = 'canManageConfiguration';

    protected string $writeCapability = 'canManageConfiguration';
}
