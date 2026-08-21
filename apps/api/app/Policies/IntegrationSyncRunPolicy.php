<?php

namespace App\Policies;

class IntegrationSyncRunPolicy extends IntegrationResourcePolicy
{
    protected ?string $viewCapability = 'canManageConfiguration';

    protected string $writeCapability = 'canManageConfiguration';
}
