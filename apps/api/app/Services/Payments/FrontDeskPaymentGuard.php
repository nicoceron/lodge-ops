<?php

namespace App\Services\Payments;

use App\Enums\MembershipRole;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

final class FrontDeskPaymentGuard
{
    public function recordTender(User $actor, string $propertyId): void
    {
        $this->assert($actor, $propertyId, [MembershipRole::Administrator, MembershipRole::Manager, MembershipRole::Operations, MembershipRole::Finance]);
    }

    public function operateCash(User $actor, string $propertyId): void
    {
        $this->assert($actor, $propertyId, [MembershipRole::Administrator, MembershipRole::Manager, MembershipRole::Operations]);
    }

    public function resolveException(User $actor, string $propertyId): void
    {
        $this->assert($actor, $propertyId, [MembershipRole::Administrator, MembershipRole::Manager, MembershipRole::Finance]);
    }

    /** @param list<MembershipRole> $roles */
    private function assert(User $actor, string $propertyId, array $roles): void
    {
        $context = app(TenantContext::class);
        $membership = $context->membership();
        if ($membership === null || ! $membership->is_active || $membership->user_id !== $actor->id
            || ! in_array($membership->role, $roles, true) || ! $context->canAccessProperty($propertyId)) {
            throw ValidationException::withMessages(['authorization' => 'You are not authorized for this payment action at this property.']);
        }
    }
}
