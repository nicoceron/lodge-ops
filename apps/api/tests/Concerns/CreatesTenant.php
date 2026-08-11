<?php

namespace Tests\Concerns;

use App\Enums\MembershipRole;
use App\Models\Membership;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Laravel\Sanctum\Sanctum;

trait CreatesTenant
{
    /** @return array{Tenant, Property, User, Membership} */
    protected function tenantEnvironment(MembershipRole $role = MembershipRole::Administrator, bool $authenticate = true): array
    {
        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($tenant);
        $property = Property::factory()->create();
        $user = User::factory()->create();
        $membership = Membership::factory()->create([
            'user_id' => $user->id,
            'property_id' => $property->id,
            'role' => $role,
        ]);
        app(TenantContext::class)->set($tenant, $membership);

        if ($authenticate) {
            Sanctum::actingAs($user);
        }

        return [$tenant, $property, $user, $membership];
    }
}
