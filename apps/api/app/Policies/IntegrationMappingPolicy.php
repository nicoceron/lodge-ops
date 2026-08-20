<?php

namespace App\Policies;

class IntegrationMappingPolicy extends IntegrationResourcePolicy
{
    protected ?string $viewCapability = 'canManageConfiguration';

    protected string $writeCapability = 'canManageConfiguration';
}
