<?php

namespace Tests\Feature;

use App\Enums\AllocationStatus;
use App\Enums\DepositStatus;
use App\Enums\FolioLineType;
use App\Enums\MembershipRole;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Enums\ResourceType;
use App\Models\Deposit;
use App\Models\FolioLine;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\Program;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\ResourceBlock;
use App\Models\ServiceOccurrence;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class StaffProjectionTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_staff_projections_never_include_another_tenants_records(): void
    {
        [$tenantA, $propertyA] = $this->tenantEnvironment(authenticate: false);
        $arrival = $this->arrivalTime($tenantA->timezone);
        Reservation::factory()->create([
            'property_id' => $propertyA->id,
            'confirmation_number' => 'TENANT-A-ONLY',
            'status' => ReservationStatus::Confirmed,
            'starts_at' => $arrival,
            'ends_at' => $arrival->addDays(2),
        ]);

        app(TenantContext::class)->clear();
        [$tenantB, , $userB] = $this->tenantEnvironment(authenticate: false);
        Sanctum::actingAs($userB);
        $headers = ['X-Tenant-ID' => $tenantB->id];
        $calendarStart = now()->subDay()->toDateString();
        $calendarEnd = now()->addDays(8)->toDateString();

        $this->withHeaders($headers)->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonMissing(['confirmation_number' => 'TENANT-A-ONLY']);
        $this->withHeaders($headers)->getJson("/api/v1/calendar?start={$calendarStart}&end={$calendarEnd}")
            ->assertOk()
            ->assertJsonMissing(['title' => 'TENANT-A-ONLY']);
        $this->withHeaders($headers)->getJson('/api/v1/operations')
            ->assertOk()
            ->assertJsonMissing(['confirmation_number' => 'TENANT-A-ONLY']);
        $this->withHeaders($headers)->getJson('/api/v1/finance')
            ->assertOk()
            ->assertJsonPath('data.summary.booked_revenue_minor', 0);
    }

    public function test_kitchen_projection_gets_dietary_counts_without_guest_identity(): void
    {
        [$tenant, $property] = $this->tenantEnvironment(MembershipRole::Kitchen);
        $guest = Guest::factory()->create([
            'first_name' => 'Private',
            'last_name' => 'Guest',
            'preferences' => ['dietary' => ['Gluten-free', 'Severe nut allergy']],
        ]);
        $arrival = $this->arrivalTime($tenant->timezone);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'primary_guest_id' => $guest->id,
            'status' => ReservationStatus::Confirmed,
            'starts_at' => $arrival,
            'ends_at' => $arrival->addDays(2),
        ]);
        $room = Resource::factory()->create(['property_id' => $property->id, 'type' => ResourceType::Room]);
        $reservation->allocations()->create([
            'resource_id' => $room->id,
            'status' => AllocationStatus::Confirmed,
            'starts_at' => $reservation->starts_at,
            'ends_at' => $reservation->ends_at,
            'quantity' => 1,
        ]);
        $headers = ['X-Tenant-ID' => $tenant->id];

        $this->withHeaders($headers)->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonMissingPath('data.arrival_parties.0.guest_name')
            ->assertDontSee('Private Guest');
        $this->withHeaders($headers)->getJson('/api/v1/operations')
            ->assertOk()
            ->assertJsonMissingPath('data.arrivals.0.guest_name')
            ->assertJsonFragment(['label' => 'Gluten-free', 'count' => 1])
            ->assertJsonFragment(['label' => 'Severe nut allergy', 'serious' => true])
            ->assertDontSee('Private Guest');
        $this->withHeaders($headers)->getJson('/api/v1/calendar?start='.$arrival->subDay()->toDateString().'&end='.$arrival->addDays(3)->toDateString())
            ->assertForbidden();
    }

    public function test_authorized_operational_roles_receive_identity_only_where_needed(): void
    {
        [$tenant, $property] = $this->tenantEnvironment(MembershipRole::Operations);
        $guest = Guest::factory()->create(['first_name' => 'Visible', 'last_name' => 'Operator']);
        $arrival = $this->arrivalTime($tenant->timezone);
        Reservation::factory()->create([
            'property_id' => $property->id,
            'primary_guest_id' => $guest->id,
            'status' => ReservationStatus::Confirmed,
            'starts_at' => $arrival,
            'ends_at' => $arrival->addDays(2),
        ]);
        $headers = ['X-Tenant-ID' => $tenant->id];

        $this->withHeaders($headers)->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.arrival_parties.0.guest_name', 'Visible Operator');
        $this->withHeaders($headers)->getJson('/api/v1/operations')
            ->assertOk()
            ->assertJsonPath('data.arrivals.0.guest_name', 'Visible Operator');
        $this->withHeaders($headers)->getJson('/api/v1/calendar?start='.$arrival->subDay()->toDateString().'&end='.$arrival->addDays(3)->toDateString())
            ->assertOk()
            ->assertJsonFragment(['type' => 'reservation', 'title' => 'Visible Operator']);
    }

    public function test_dashboard_reports_calendar_nights_as_an_integer(): void
    {
        [$tenant, $property] = $this->tenantEnvironment();
        $arrival = CarbonImmutable::now($tenant->timezone)->startOfDay()->addHours(15)->utc();
        Reservation::factory()->create([
            'property_id' => $property->id,
            'status' => ReservationStatus::Confirmed,
            'starts_at' => $arrival,
            'ends_at' => $arrival->addDays(4)->subHours(4),
        ]);

        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.arrival_parties.0.nights', 4);
    }

    public function test_calendar_projection_contains_resources_allocations_occurrences_and_blocks(): void
    {
        [$tenant, $property] = $this->tenantEnvironment();
        $arrival = $this->arrivalTime($tenant->timezone);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'status' => ReservationStatus::Confirmed,
            'starts_at' => $arrival,
            'ends_at' => $arrival->addDays(2),
        ]);
        $room = Resource::factory()->create(['property_id' => $property->id, 'name' => 'Projection Room']);
        $allocation = $reservation->allocations()->create([
            'resource_id' => $room->id,
            'status' => AllocationStatus::Confirmed,
            'starts_at' => $reservation->starts_at,
            'ends_at' => $reservation->ends_at,
            'quantity' => 1,
        ]);
        ResourceBlock::query()->create([
            'resource_id' => $room->id,
            'starts_at' => $arrival->addDays(3),
            'ends_at' => $arrival->addDays(4),
            'reason' => 'Maintenance window',
        ]);
        $program = Program::query()->create([
            'property_id' => $property->id,
            'name' => 'Ridge walk',
            'default_duration_minutes' => 180,
            'capacity' => 6,
            'price_minor' => 0,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        ServiceOccurrence::query()->create([
            'program_id' => $program->id,
            'property_id' => $property->id,
            'starts_at' => $arrival->addDay(),
            'ends_at' => $arrival->addDay()->addHours(3),
            'capacity' => 6,
            'is_cancelled' => false,
        ]);

        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->getJson('/api/v1/calendar?start='.$arrival->subDay()->toDateString().'&end='.$arrival->addDays(5)->toDateString())
            ->assertOk()
            ->assertJsonFragment(['id' => $room->id, 'name' => 'Projection Room'])
            ->assertJsonFragment(['id' => $allocation->id, 'resource_id' => $room->id])
            ->assertJsonFragment(['type' => 'activity', 'title' => 'Ridge walk'])
            ->assertJsonFragment(['type' => 'resource_block', 'title' => 'Maintenance window'])
            ->assertJsonStructure(['data', 'range' => ['start', 'end', 'timezone'], 'resources', 'allocations', 'summary' => ['hard_conflicts', 'unassigned_reservations', 'suggestions']]);
    }

    public function test_finance_projection_is_role_restricted_minimal_and_tenant_scoped(): void
    {
        [$tenant, $property] = $this->tenantEnvironment(MembershipRole::Finance);
        $tenant->update(['currency' => 'USD']);
        $guest = Guest::factory()->create(['first_name' => 'Financial', 'last_name' => 'Privacy', 'email' => 'finance-private@example.com']);
        $arrival = CarbonImmutable::now($tenant->timezone)->startOfMonth()->addDays(2)->addHours(15)->utc();
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'primary_guest_id' => $guest->id,
            'confirmation_number' => 'FIN-100',
            'status' => ReservationStatus::Confirmed,
            'starts_at' => $arrival,
            'ends_at' => $arrival->addDays(2),
            'currency' => 'USD',
            'total_minor' => 100000,
            'source' => 'Direct',
        ]);
        $payment = Payment::query()->create([
            'reservation_id' => $reservation->id,
            'status' => PaymentStatus::Succeeded,
            'method' => 'card',
            'currency' => 'USD',
            'amount_minor' => 40000,
            'processed_at' => now(),
        ]);
        Deposit::query()->create([
            'reservation_id' => $reservation->id,
            'status' => DepositStatus::Due,
            'currency' => 'USD',
            'amount_minor' => 15000,
            'due_at' => now()->subDay(),
        ]);
        FolioLine::query()->create([
            'reservation_id' => $reservation->id,
            'payment_id' => $payment->id,
            'type' => FolioLineType::Payment,
            'description' => 'Payment received',
            'quantity' => 1,
            'unit_amount_minor' => -40000,
            'amount_minor' => -40000,
            'currency' => 'USD',
            'posted_at' => now(),
        ]);

        $response = $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/finance');
        $response->assertOk()
            ->assertJsonPath('data.summary.booked_revenue_minor', 100000)
            ->assertJsonPath('data.summary.cash_collected_minor', 40000)
            ->assertJsonPath('data.summary.receivables_minor', 60000)
            ->assertJsonPath('data.summary.overdue_deposits_minor', 15000)
            ->assertJsonPath('data.recent_folios.0.confirmation_number', 'FIN-100')
            ->assertJsonMissingPath('data.recent_folios.0.guest_name')
            ->assertDontSee('Financial Privacy')
            ->assertDontSee('finance-private@example.com');

        app(TenantContext::class)->clear();
        [$kitchenTenant, , $kitchenUser] = $this->tenantEnvironment(MembershipRole::Kitchen, authenticate: false);
        Sanctum::actingAs($kitchenUser);
        $this->withHeader('X-Tenant-ID', $kitchenTenant->id)
            ->getJson('/api/v1/finance')
            ->assertForbidden();
    }

    public function test_finance_projection_honors_a_property_scoped_membership(): void
    {
        [$tenant, $property] = $this->tenantEnvironment(MembershipRole::Finance);
        $tenant->update(['currency' => 'USD']);
        $otherProperty = Property::factory()->create(['name' => 'Outside finance scope']);
        $arrival = CarbonImmutable::now($tenant->timezone)->startOfMonth()->addDays(2)->addHours(15)->utc();

        Reservation::factory()->create([
            'property_id' => $property->id,
            'confirmation_number' => 'FIN-IN-SCOPE',
            'status' => ReservationStatus::Confirmed,
            'starts_at' => $arrival,
            'ends_at' => $arrival->addDays(2),
            'currency' => 'USD',
            'total_minor' => 75_000,
        ]);
        Reservation::factory()->create([
            'property_id' => $otherProperty->id,
            'confirmation_number' => 'FIN-OUTSIDE-SCOPE',
            'status' => ReservationStatus::Confirmed,
            'starts_at' => $arrival,
            'ends_at' => $arrival->addDays(2),
            'currency' => 'USD',
            'total_minor' => 125_000,
        ]);

        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->getJson('/api/v1/finance')
            ->assertOk()
            ->assertJsonPath('data.summary.booked_revenue_minor', 75_000)
            ->assertJsonFragment(['confirmation_number' => 'FIN-IN-SCOPE'])
            ->assertJsonMissing(['confirmation_number' => 'FIN-OUTSIDE-SCOPE']);
    }

    private function arrivalTime(string $timezone): CarbonImmutable
    {
        return CarbonImmutable::now($timezone)->startOfDay()->addHours(15)->utc();
    }
}
