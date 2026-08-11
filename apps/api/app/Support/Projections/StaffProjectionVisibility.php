<?php

namespace App\Support\Projections;

use App\Enums\MembershipRole;
use App\Support\Tenancy\TenantContext;

final class StaffProjectionVisibility
{
    public function __construct(private readonly TenantContext $context) {}

    public function canSeeGuestIdentity(): bool
    {
        return in_array($this->role(), [
            MembershipRole::Administrator,
            MembershipRole::Manager,
            MembershipRole::Sales,
            MembershipRole::Operations,
            MembershipRole::Guide,
        ], true);
    }

    public function canSeeDietaryDetails(): bool
    {
        return in_array($this->role(), [
            MembershipRole::Administrator,
            MembershipRole::Manager,
            MembershipRole::Operations,
            MembershipRole::Kitchen,
        ], true);
    }

    public function role(): ?MembershipRole
    {
        return $this->context->membership()?->role;
    }
}
