<?php

namespace App\Policies;

use App\Models\GuestPaymentEvidence;
use App\Models\User;

class GuestPaymentEvidencePolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageGuestMoney($user);
    }

    public function view(User $user, GuestPaymentEvidence $evidence): bool
    {
        return $this->canManageGuestMoney($user, $evidence);
    }

    public function download(User $user, GuestPaymentEvidence $evidence): bool
    {
        return $this->canManageGuestMoney($user, $evidence);
    }

    public function review(User $user, GuestPaymentEvidence $evidence): bool
    {
        return $this->canManageGuestMoney($user, $evidence);
    }
}
