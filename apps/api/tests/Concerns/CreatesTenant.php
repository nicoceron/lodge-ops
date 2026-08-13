<?php

namespace Tests\Concerns;

use App\Enums\MembershipRole;
use App\Enums\ResourceKind;
use App\Models\Membership;
use App\Models\Property;
use App\Models\ResourceCategory;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ResourceCatalog;
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
        app(ResourceCatalog::class)->ensure($property, $this->resourceCategoryDefinitions());
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

    protected function category(Property|string $property, string $slug): ResourceCategory
    {
        $propertyModel = $property instanceof Property ? $property : Property::query()->findOrFail($property);
        app(ResourceCatalog::class)->ensure($propertyModel, $this->resourceCategoryDefinitions());

        return app(ResourceCatalog::class)->category($propertyModel, $slug);
    }

    /** @return list<array<string, mixed>> */
    private function resourceCategoryDefinitions(): array
    {
        return [
            ['kind' => ResourceKind::Place, 'slug' => 'room', 'name' => 'Room', 'counts_as_stay' => true, 'default_capacity' => 2, 'sort_order' => 10],
            ['kind' => ResourceKind::Place, 'slug' => 'venue', 'name' => 'Venue', 'sort_order' => 20],
            ['kind' => ResourceKind::Asset, 'slug' => 'horse', 'name' => 'Horse', 'sort_order' => 30],
            ['kind' => ResourceKind::Asset, 'slug' => 'boat', 'name' => 'Boat', 'default_capacity' => 3, 'sort_order' => 40],
            ['kind' => ResourceKind::Asset, 'slug' => 'vehicle', 'name' => 'Vehicle', 'default_capacity' => 4, 'sort_order' => 50],
            ['kind' => ResourceKind::Asset, 'slug' => 'equipment', 'name' => 'Equipment', 'sort_order' => 60],
            ['kind' => ResourceKind::Crew, 'slug' => 'guide', 'name' => 'Guide', 'sort_order' => 70],
            ['kind' => ResourceKind::Crew, 'slug' => 'staff', 'name' => 'Staff', 'sort_order' => 80],
        ];
    }
}
