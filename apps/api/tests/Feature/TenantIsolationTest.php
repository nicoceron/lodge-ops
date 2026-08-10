<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Models\Guest;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_tenant_scope_and_api_route_binding_never_expose_another_tenants_guests(): void
    {
        [$tenantA] = $this->tenantEnvironment(authenticate: false);
        $guestA = Guest::factory()->create(['email' => 'a@example.com']);

        app(TenantContext::class)->clear();
        [$tenantB, , $userB] = $this->tenantEnvironment(authenticate: false);
        $guestB = Guest::factory()->create(['email' => 'b@example.com']);
        Sanctum::actingAs($userB);

        $this->withHeader('X-Tenant-ID', $tenantB->id)
            ->getJson('/api/v1/guests')
            ->assertOk()
            ->assertJsonFragment(['id' => $guestB->id])
            ->assertJsonMissing(['id' => $guestA->id]);

        $this->withHeader('X-Tenant-ID', $tenantB->id)
            ->getJson("/api/v1/guests/{$guestA->id}")
            ->assertNotFound();

        $this->withHeader('X-Tenant-ID', $tenantA->id)
            ->getJson('/api/v1/guests')
            ->assertForbidden();
    }

    public function test_tenant_owned_queries_fail_closed_without_a_context(): void
    {
        $this->tenantEnvironment(authenticate: false);
        Guest::factory()->create();
        app(TenantContext::class)->clear();

        $this->assertSame(0, Guest::query()->count());
    }

    public function test_viewer_can_read_but_cannot_write(): void
    {
        [$tenant] = $this->tenantEnvironment(MembershipRole::Viewer);

        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->getJson('/api/v1/guests')
            ->assertOk();

        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->postJson('/api/v1/guests', ['first_name' => 'Blocked'])
            ->assertForbidden();
    }

    public function test_database_composite_foreign_keys_reject_cross_tenant_relationships(): void
    {
        [$tenantA] = $this->tenantEnvironment(authenticate: false);
        app(TenantContext::class)->clear();
        $tenantB = Tenant::factory()->create();
        app(TenantContext::class)->set($tenantB);
        $propertyB = Property::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('reservations')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantA->id,
            'property_id' => $propertyB->id,
            'confirmation_number' => 'RSV-CROSS-TENANT',
            'status' => 'draft',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(2),
            'adults' => 1,
            'children' => 0,
            'currency' => 'USD',
            'subtotal_minor' => 0,
            'tax_minor' => 0,
            'total_minor' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_finance_and_kitchen_roles_only_mutate_their_own_workspaces(): void
    {
        [$financeTenant, $financeProperty] = $this->tenantEnvironment(MembershipRole::Finance);
        $reservation = Reservation::factory()->create(['property_id' => $financeProperty->id]);

        $this->withHeader('X-Tenant-ID', $financeTenant->id)
            ->postJson('/api/v1/guests', ['first_name' => 'Not allowed'])
            ->assertForbidden();
        $this->withHeader('X-Tenant-ID', $financeTenant->id)
            ->postJson('/api/v1/payments', [
                'reservation_id' => $reservation->id,
                'method' => 'cash',
                'amount_minor' => 5000,
                'captured' => true,
            ])->assertCreated();

        app(TenantContext::class)->clear();
        [$kitchenTenant, $kitchenProperty] = $this->tenantEnvironment(MembershipRole::Kitchen);
        $this->withHeader('X-Tenant-ID', $kitchenTenant->id)
            ->postJson('/api/v1/tasks', [
                'property_id' => $kitchenProperty->id,
                'title' => 'Prepare allergen-safe service',
            ])->assertCreated();
        $this->withHeader('X-Tenant-ID', $kitchenTenant->id)
            ->postJson('/api/v1/guests', ['first_name' => 'Not allowed'])
            ->assertForbidden();
    }
}
