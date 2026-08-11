<?php

namespace App\Policies;

class MembershipPolicy extends TenantResourcePolicy
{
    protected ?string $viewCapability = 'canManageTeam';

    protected string $writeCapability = 'canManageTeam';
}
