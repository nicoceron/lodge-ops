<?php

namespace App\Policies;

class CommunicationDeliveryEventPolicy extends TenantResourcePolicy
{
    protected ?string $viewCapability = 'canManageConfiguration';

    protected string $writeCapability = 'canManageConfiguration';
}
