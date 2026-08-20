<?php

namespace App\Policies;

use App\Models\CommercialPromotion;
use App\Models\User;

class CommercialPromotionPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageReservations($user);
    }

    public function view(User $user, CommercialPromotion $record): bool
    {
        return $this->canManageReservations($user, $record);
    }

    public function create(User $user): bool
    {
        return $this->canManageConfiguration($user);
    }

    public function update(User $user, CommercialPromotion $record): bool
    {
        return $this->canManageConfiguration($user, $record);
    }

    public function delete(User $user, CommercialPromotion $record): bool
    {
        return $this->canManageConfiguration($user, $record);
    }
}
