<?php

namespace App\Policies;

use App\Models\Communication;
use App\Models\Property;
use App\Models\User;

class CommunicationPolicy extends TenantResourcePolicy
{
    protected ?string $viewCapability = 'canManageReservations';

    protected string $writeCapability = 'canManageReservations';

    public function retry(User $user, Communication $communication): bool
    {
        return $this->canManageReservations($user, $communication);
    }

    public function newResend(User $user, Communication $communication): bool
    {
        return $this->canManageReservations($user, $communication);
    }

    public function testSend(User $user, Property $property): bool
    {
        return $this->canManageReservations($user, $property);
    }
}
