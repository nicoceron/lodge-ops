<?php

namespace App\Policies;

class AutomationRulePolicy extends TenantResourcePolicy
{
    protected ?string $viewCapability = 'canManageConfiguration';

    protected string $writeCapability = 'canManageConfiguration';
}
