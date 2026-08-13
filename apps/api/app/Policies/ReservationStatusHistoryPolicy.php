<?php

namespace App\Policies;

use App\Models\ReservationStatusHistory;
use App\Models\User;

class ReservationStatusHistoryPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, ReservationStatusHistory $history): bool
    {
        return $this->canView($user, $history);
    }
}
