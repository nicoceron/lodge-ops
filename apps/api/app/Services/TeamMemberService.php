<?php

namespace App\Services;

use App\Enums\MembershipRole;
use App\Models\Membership;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TeamMemberService
{
    public function __construct(private readonly TenantContext $context) {}

    public function invite(string $name, string $email, MembershipRole $role, ?string $propertyId): Membership
    {
        $this->authorizeOwner();
        $propertyId = $this->validateProperty($propertyId);
        $existing = User::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])->first();

        $membership = DB::transaction(function () use ($name, $email, $role, $propertyId, $existing): Membership {
            $user = $existing ?? User::query()->create([
                'name' => trim($name),
                'email' => mb_strtolower(trim($email)),
                'password' => Str::random(64),
            ]);

            return Membership::query()->updateOrCreate(
                ['user_id' => $user->id],
                ['property_id' => $propertyId, 'role' => $role, 'is_active' => true],
            )->load(['user', 'property']);
        }, 3);

        if ($existing === null) {
            Password::sendResetLink(['email' => $membership->user->email]);
        }

        return $membership;
    }

    public function update(Membership $membership, MembershipRole $role, ?string $propertyId, bool $isActive): Membership
    {
        $this->authorizeOwner();
        if ($membership->tenant_id !== $this->context->id()) {
            throw ValidationException::withMessages(['membership' => 'The team member does not belong to this lodge.']);
        }
        $propertyId = $this->validateProperty($propertyId);

        return DB::transaction(function () use ($membership, $role, $propertyId, $isActive): Membership {
            Tenant::query()->whereKey($this->context->id())->lockForUpdate()->firstOrFail();
            $locked = Membership::query()->whereKey($membership->id)->lockForUpdate()->firstOrFail();
            $removesOwner = $locked->is_active
                && $locked->role === MembershipRole::Owner
                && (! $isActive || $role !== MembershipRole::Owner);
            if ($removesOwner) {
                $otherOwners = Membership::query()
                    ->whereKeyNot($locked->id)
                    ->where('role', MembershipRole::Owner)
                    ->where('is_active', true)
                    ->exists();
                if (! $otherOwners) {
                    throw new DomainException('Assign another active owner before changing the last owner.');
                }
            }

            $locked->update(['role' => $role, 'property_id' => $propertyId, 'is_active' => $isActive]);

            return $locked->load(['user', 'property']);
        }, 3);
    }

    private function authorizeOwner(): void
    {
        if ($this->context->membership()?->role !== MembershipRole::Owner) {
            throw new AuthorizationException('Only lodge owners may manage team access.');
        }
    }

    private function validateProperty(?string $propertyId): ?string
    {
        if ($propertyId === null || $propertyId === '') {
            return null;
        }
        if (! Property::query()->whereKey($propertyId)->exists()) {
            throw ValidationException::withMessages(['property_id' => 'Select a property in the current lodge.']);
        }

        return $propertyId;
    }
}
