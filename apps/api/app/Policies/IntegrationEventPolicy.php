<?php

namespace App\Policies;

class IntegrationEventPolicy extends IntegrationResourcePolicy
{
    protected ?string $viewCapability = 'canManageConfiguration';

    protected string $writeCapability = 'canManageConfiguration';
}
