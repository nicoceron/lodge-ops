<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\BookingQuote;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

final class BookingQuotePolicy extends TenantPolicy
{
    public function view(User $user, BookingQuote $quote): bool
    {
        if (! $this->canView($user, $quote)) {
            return false;
        }

        return in_array(app(TenantContext::class)->membership()?->role, [
            MembershipRole::Administrator,
            MembershipRole::Owner,
            MembershipRole::Manager,
            MembershipRole::Sales,
            MembershipRole::Finance,
        ], true);
    }
}
