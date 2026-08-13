<?php

namespace App\Policies;

class CalendarFeedPolicy extends TenantResourcePolicy
{
    protected ?string $viewCapability = 'canManageConfiguration';

    protected string $writeCapability = 'canManageConfiguration';

    protected string $deleteCapability = 'canManageConfiguration';
}
