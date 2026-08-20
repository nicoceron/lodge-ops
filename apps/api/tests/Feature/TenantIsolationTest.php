<?php

namespace Tests\Feature;

use App\Enums\DepositStatus;
use App\Enums\MembershipRole;
use App\Enums\PaymentStatus;
use App\Models\Deposit;
use App\Models\Guest;
use App\Models\OperationalTask;
use App\Models\Payment;
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
            ->postJson("/api/v1/reservations/{$reservation->id}/front-desk-payments", [
                'channel' => 'bank_transfer',
                'amount_minor' => 5000,
            ])->assertCreated();

        app(TenantContext::class)->clear();
        [$kitchenTenant, $kitchenProperty] = $this->tenantEnvironment(MembershipRole::Kitchen);
        $otherKitchenProperty = Property::factory()->create();
        $kitchenTask = OperationalTask::query()->create([
            'property_id' => $kitchenProperty->id,
            'title' => 'Prepare allergen-safe service',
            'status' => 'todo',
            'priority' => 'high',
            'metadata' => ['assignee_role' => MembershipRole::Kitchen->value],
        ]);
        OperationalTask::query()->create([
            'property_id' => $otherKitchenProperty->id,
            'title' => 'Other property kitchen task',
            'status' => 'todo',
            'priority' => 'high',
            'metadata' => ['assignee_role' => MembershipRole::Kitchen->value],
        ]);
        $this->withHeader('X-Tenant-ID', $kitchenTenant->id)
            ->postJson('/api/v1/tasks', [
                'property_id' => $kitchenProperty->id,
                'title' => 'Prepare allergen-safe service',
            ])->assertForbidden();
        $this->withHeader('X-Tenant-ID', $kitchenTenant->id)
            ->putJson("/api/v1/tasks/{$kitchenTask->id}", ['status' => 'done'])
            ->assertOk()
            ->assertJsonPath('data.status', 'done');
        $tasks = $this->withHeader('X-Tenant-ID', $kitchenTenant->id)
            ->getJson('/api/v1/tasks')
            ->assertOk();
        $this->assertSame([$kitchenTask->id], collect($tasks->json('data'))->pluck('id')->all());
        $this->withHeader('X-Tenant-ID', $kitchenTenant->id)
            ->postJson('/api/v1/guests', ['first_name' => 'Not allowed'])
            ->assertForbidden();
    }

    public function test_property_scoped_operations_cannot_create_tasks_for_another_property_or_reservation(): void
    {
        [$tenant, $property] = $this->tenantEnvironment(MembershipRole::Operations);
        $otherProperty = Property::factory()->for($tenant)->create();
        $otherReservation = Reservation::factory()->create(['property_id' => $otherProperty->id]);

        $this->postJson('/api/v1/tasks', [
            'property_id' => $otherProperty->id,
            'title' => 'Cross-property task',
            'status' => 'todo',
            'priority' => 'normal',
        ])->assertStatus(400);

        $this->postJson('/api/v1/tasks', [
            'property_id' => $property->id,
            'reservation_id' => $otherReservation->id,
            'title' => 'Mismatched reservation task',
            'status' => 'todo',
            'priority' => 'normal',
        ])->assertStatus(400);
    }

    public function test_property_scoped_finance_api_cannot_read_or_write_another_property_ledger(): void
    {
        [$tenant, $property] = $this->tenantEnvironment(MembershipRole::Finance);
        $otherProperty = Property::factory()->create();
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'status' => 'confirmed',
            'currency' => 'USD',
            'total_minor' => 10_000,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);
        $otherReservation = Reservation::factory()->create([
            'property_id' => $otherProperty->id,
            'status' => 'confirmed',
            'currency' => 'USD',
            'total_minor' => 20_000,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);
        $payment = Payment::query()->create([
            'reservation_id' => $reservation->id,
            'status' => PaymentStatus::Succeeded,
            'method' => 'cash',
            'currency' => 'USD',
            'amount_minor' => 1000,
            'processed_at' => now(),
        ]);
        $otherPayment = Payment::query()->create([
            'reservation_id' => $otherReservation->id,
            'status' => PaymentStatus::Succeeded,
            'method' => 'cash',
            'currency' => 'USD',
            'amount_minor' => 2000,
            'processed_at' => now(),
        ]);
        $deposit = Deposit::query()->create([
            'reservation_id' => $reservation->id,
            'status' => DepositStatus::Due,
            'schedule_type' => 'manual',
            'currency' => 'USD',
            'amount_minor' => 1000,
            'due_at' => now(),
        ]);
        $otherDeposit = Deposit::query()->create([
            'reservation_id' => $otherReservation->id,
            'status' => DepositStatus::Due,
            'schedule_type' => 'manual',
            'currency' => 'USD',
            'amount_minor' => 2000,
            'due_at' => now(),
        ]);
        $headers = ['X-Tenant-ID' => $tenant->id];

        $payments = $this->withHeaders($headers)->getJson('/api/v1/payments')->assertOk();
        $this->assertSame([$payment->id], collect($payments->json('data'))->pluck('id')->all());
        $this->withHeaders($headers)->getJson("/api/v1/payments/{$otherPayment->id}")->assertForbidden();
        $this->withHeaders($headers)->postJson("/api/v1/reservations/{$otherReservation->id}/front-desk-payments", [
            'channel' => 'bank_transfer',
            'amount_minor' => 500,
        ])->assertForbidden();

        $deposits = $this->withHeaders($headers)->getJson('/api/v1/deposits')->assertOk();
        $this->assertSame([$deposit->id], collect($deposits->json('data'))->pluck('id')->all());
        $this->withHeaders($headers)->getJson("/api/v1/deposits/{$otherDeposit->id}")->assertForbidden();

        $summary = $this->withHeaders($headers)->getJson('/api/v1/financial-summary?currency=USD&starts_at='.urlencode(now()->subDays(2)->toDateString()).'&ends_at='.urlencode(now()->addDays(2)->toDateString()))->assertOk();
        $summary->assertJsonPath('data.booked_minor', 10_000);
    }

    public function test_reservation_api_is_scoped_for_property_memberships(): void
    {
        [$tenant, $property] = $this->tenantEnvironment(MembershipRole::Sales);
        $otherProperty = Property::factory()->create();
        $reservation = Reservation::factory()->create(['property_id' => $property->id]);
        $otherReservation = Reservation::factory()->create(['property_id' => $otherProperty->id]);
        $headers = ['X-Tenant-ID' => $tenant->id];

        $response = $this->withHeaders($headers)->getJson('/api/v1/reservations')->assertOk();
        $this->assertSame([$reservation->id], collect($response->json('data'))->pluck('id')->all());
        $this->withHeaders($headers)->getJson("/api/v1/reservations/{$otherReservation->id}")->assertForbidden();
    }
}
