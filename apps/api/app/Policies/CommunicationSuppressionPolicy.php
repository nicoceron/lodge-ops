<?php

namespace App\Policies;

class CommunicationSuppressionPolicy extends TenantResourcePolicy
{
    protected ?string $viewCapability = 'canManageConfiguration';

    protected string $writeCapability = 'canManageConfiguration';
}
