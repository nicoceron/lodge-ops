<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Voucher;

class VoucherPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageReservations($user);
    }

    public function view(User $user, Voucher $record): bool
    {
        return $this->canManageReservations($user, $record);
    }

    public function create(User $user): bool
    {
        return $this->canManageConfiguration($user);
    }

    public function update(User $user, Voucher $record): bool
    {
        return false;
    }

    public function delete(User $user, Voucher $record): bool
    {
        return false;
    }
}
