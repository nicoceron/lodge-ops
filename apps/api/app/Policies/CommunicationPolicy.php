<?php

namespace App\Policies;

class CommunicationPolicy extends TenantResourcePolicy
{
    protected ?string $viewCapability = 'canManageReservations';

    protected string $writeCapability = 'canManageReservations';
}
