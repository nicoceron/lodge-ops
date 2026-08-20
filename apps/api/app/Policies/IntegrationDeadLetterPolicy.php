<?php

namespace App\Policies;

class IntegrationDeadLetterPolicy extends IntegrationResourcePolicy
{
    protected ?string $viewCapability = 'canManageConfiguration';

    protected string $writeCapability = 'canManageConfiguration';
}
