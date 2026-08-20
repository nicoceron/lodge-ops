<?php

namespace App\Policies;

class ReservationMilestoneOccurrencePolicy extends TenantResourcePolicy
{
    protected ?string $viewCapability = 'canManageReservations';

    protected string $writeCapability = 'canManageConfiguration';
}
