<?php

namespace App\Policies;

use App\Models\Proposal;
use App\Models\User;

class ProposalPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageReservations($user);
    }

    public function view(User $user, Proposal $proposal): bool
    {
        return $this->canManageReservations($user, $proposal);
    }

    public function create(User $user): bool
    {
        return $this->canManageReservations($user);
    }

    public function update(User $user, Proposal $proposal): bool
    {
        return $this->canManageReservations($user, $proposal);
    }

    public function send(User $user, Proposal $proposal): bool
    {
        return $this->canManageReservations($user, $proposal);
    }

    public function convert(User $user, Proposal $proposal): bool
    {
        return $this->canManageReservations($user, $proposal);
    }
}
