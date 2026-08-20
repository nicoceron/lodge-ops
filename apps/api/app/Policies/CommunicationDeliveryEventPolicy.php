<?php

namespace App\Policies;

class CommunicationDeliveryEventPolicy extends TenantResourcePolicy
{
    protected ?string $viewCapability = 'canManageReservations';

    protected string $writeCapability = 'canManageConfiguration';
}
