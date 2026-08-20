<?php

namespace App\Policies;

class DeliveryAttemptPolicy extends TenantResourcePolicy
{
    protected ?string $viewCapability = 'canManageReservations';

    protected string $writeCapability = 'canManageConfiguration';
}
