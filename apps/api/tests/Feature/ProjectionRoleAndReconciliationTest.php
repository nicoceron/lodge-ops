<?php

namespace Tests\Feature;

use App\Enums\AllocationStatus;
use App\Enums\MembershipRole;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Enums\TaskStatus;
use App\Models\CommissionAccrual;
use App\Models\CostRecord;
use App\Models\Guest;
use App\Models\GuestPortalProfile;
use App\Models\Membership;
use App\Models\OperationalTask;
use App\Models\Payment;
use App\Models\Program;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\ServiceOccurrence;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class ProjectionRoleAndReconciliationTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_finance_projection_reconciles_program_cost_and_commission_components_in_tenant_currency(): void
    {
        [$tenant, $property, , $membership] = $this->tenantEnvironment(MembershipRole::Finance);
        $membership->update(['property_id' => null]);
        app(TenantContext::class)->set($tenant, $membership);
        $tenant->update(['currency' => 'USD']);
        $periodDate = CarbonImmutable::now($tenant->timezone)->startOfMonth()->addDays(2)->addHours(15)->utc();
        $program = Program::query()->create([
            'property_id' => $property->id,
            'name' => 'Andean Expedition',
            'currency' => 'USD',
        ]);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'program_id' => $program->id,
            'status' => ReservationStatus::Confirmed,
            'source' => 'Agency',
            'currency' => 'USD',
            'starts_at' => $periodDate,
            'ends_at' => $periodDate->addDays(3),
            'subtotal_minor' => 100_000,
            'tax_minor' => 0,
            'total_minor' => 100_000,
        ]);
        Payment::query()->create([
            'reservation_id' => $reservation->id,
            'status' => PaymentStatus::Succeeded,
            'method' => 'card',
            'currency' => 'USD',
            'amount_minor' => 40_000,
            'processed_at' => $periodDate,
        ]);
        Payment::query()->create([
            'reservation_id' => $reservation->id,
            'status' => PaymentStatus::Succeeded,
            'method' => 'card',
            'currency' => 'EUR',
            'amount_minor' => 900_000,
            'processed_at' => $periodDate,
        ]);
        CostRecord::query()->create([
            'reservation_id' => $reservation->id,
            'program_id' => $program->id,
            'kind' => 'actual',
            'category' => 'guide',
            'description' => 'Guide day',
            'currency' => 'USD',
            'amount_minor' => 10_000,
            'occurred_at' => $periodDate,
        ]);
        CostRecord::query()->create([
            'kind' => 'actual',
            'category' => 'operations',
            'description' => 'Shared operating cost',
            'currency' => 'USD',
            'amount_minor' => 5_000,
            'occurred_at' => $periodDate,
        ]);
        CostRecord::query()->create([
            'reservation_id' => $reservation->id,
            'kind' => 'actual',
            'category' => 'foreign',
            'description' => 'Foreign currency cost',
            'currency' => 'EUR',
            'amount_minor' => 800_000,
            'occurred_at' => $periodDate,
        ]);
        CommissionAccrual::query()->create([
            'reservation_id' => $reservation->id,
            'payee_type' => 'agency',
            'payee_name' => 'Summit Travel',
            'rate_basis_points' => 1000,
            'base_amount_minor' => 100_000,
            'amount_minor' => 10_000,
            'currency' => 'USD',
            'status' => 'accrued',
        ]);
        CommissionAccrual::query()->create([
            'reservation_id' => $reservation->id,
            'payee_type' => 'agency',
            'payee_name' => 'Foreign Travel',
            'rate_basis_points' => 1000,
            'base_amount_minor' => 900_000,
            'amount_minor' => 90_000,
            'currency' => 'EUR',
            'status' => 'accrued',
        ]);
        Reservation::factory()->create([
            'property_id' => $property->id,
            'program_id' => $program->id,
            'status' => ReservationStatus::Confirmed,
            'currency' => 'EUR',
            'starts_at' => $periodDate,
            'ends_at' => $periodDate->addDays(2),
            'total_minor' => 700_000,
        ]);

        $response = $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/finance');

        $response->assertOk()
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.summary.available', true)
            ->assertJsonPath('data.summary.source', 'native_fallback')
            ->assertJsonPath('data.summary.booked_revenue_minor', 100_000)
            ->assertJsonPath('data.summary.cash_collected_minor', 40_000)
            ->assertJsonPath('data.summary.receivables_minor', 60_000)
            ->assertJsonPath('data.summary.loaded_costs_minor', 15_000)
            ->assertJsonPath('data.summary.commission_accruals_minor', 10_000)
            ->assertJsonPath('data.summary.margin_minor', 75_000)
            ->assertJsonPath('data.channels.0.commission_accruals_minor', 10_000)
            ->assertJsonPath('data.channels.0.net_revenue_minor', 90_000)
            ->assertJsonPath('data.reconciliation.currency_policy', 'native_currency_only')
            ->assertJsonPath('data.reconciliation.difference_minor', 0)
            ->assertJsonPath('data.reconciliation.program_difference_minor', 0)
            ->assertJsonPath('data.reconciliation.is_balanced', true)
            ->assertJsonPath('data.conversion.complete', false)
            ->assertJsonPath('data.consolidated_totals.booked_revenue_minor', null)
            ->assertJsonFragment([
                'from_currency' => 'EUR',
                'to_currency' => 'USD',
                'status' => 'missing_rate',
            ]);

        $programRows = collect($response->json('data.programs'));
        $this->assertSame([
            'program_id' => $program->id,
            'program' => 'Andean Expedition',
            'revenue_minor' => 100_000,
            'loaded_costs_minor' => 10_000,
            'commission_accruals_minor' => 10_000,
            'bookings' => 1,
            'margin_minor' => 80_000,
        ], $programRows->firstWhere('program_id', $program->id));
        $this->assertSame(-5_000, $programRows->firstWhere('program_id', null)['margin_minor']);
    }

    public function test_kitchen_projection_aggregates_every_unique_companion_and_only_returns_kitchen_work(): void
    {
        [$tenant, $property] = $this->tenantEnvironment(MembershipRole::Kitchen);
        $primary = Guest::factory()->create([
            'first_name' => 'Private',
            'last_name' => 'Primary',
            'preferences' => ['dietary' => ['Gluten-free']],
        ]);
        $companion = Guest::factory()->create([
            'preferences' => ['dietary' => ['Gluten-free', 'Severe shellfish allergy']],
        ]);
        $vegetarian = Guest::factory()->create([
            'preferences' => ['dietary_requirements' => 'Vegetarian'],
        ]);
        $arrival = CarbonImmutable::now($tenant->timezone)->startOfDay()->addHours(15)->utc();
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'primary_guest_id' => $primary->id,
            'status' => ReservationStatus::Confirmed,
            'starts_at' => $arrival,
            'ends_at' => $arrival->addDays(2),
            'adults' => 3,
            'children' => 0,
        ]);
        foreach ([$primary, $companion, $vegetarian] as $guest) {
            DB::table('reservation_guests')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenant->id,
                'reservation_id' => $reservation->id,
                'guest_id' => $guest->id,
                'role' => $guest->is($primary) ? 'primary' : 'companion',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        GuestPortalProfile::query()->create([
            'reservation_id' => $reservation->id,
            'guest_id' => $primary->id,
            'profile' => [],
            'travel' => [],
            'preferences' => ['allergies' => 'Severe nut allergy'],
            'consented_at' => now(),
        ]);
        OperationalTask::query()->create([
            'property_id' => $property->id,
            'title' => 'Prepare allergy-safe meal',
            'status' => TaskStatus::Todo,
            'metadata' => ['role' => MembershipRole::Kitchen->value],
        ]);
        OperationalTask::query()->create([
            'property_id' => $property->id,
            'title' => 'Turn over room',
            'status' => TaskStatus::Todo,
            'metadata' => ['role' => MembershipRole::Housekeeping->value],
        ]);

        $response = $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/operations');

        $response->assertOk()
            ->assertJsonPath('data.role_scope.role', 'kitchen')
            ->assertJsonPath('data.role_scope.visible_sections', ['tasks', 'arrivals', 'kitchen'])
            ->assertJsonPath('data.privacy.can_view_guest_identity', false)
            ->assertJsonPath('data.privacy.can_view_dietary_details', true)
            ->assertJsonPath('data.privacy.restricted_fields', ['arrivals.guest_name'])
            ->assertJsonCount(1, 'data.tasks')
            ->assertJsonPath('data.tasks.0.title', 'Prepare allergy-safe meal')
            ->assertJsonPath('data.kitchen.available', true)
            ->assertJsonPath('data.kitchen.guest_count', 3)
            ->assertJsonPath('data.housekeeping.available', false)
            ->assertJsonPath('data.guide_assignments', [])
            ->assertJsonMissingPath('data.arrivals.0.guest_name')
            ->assertJsonFragment(['label' => 'Gluten-free', 'count' => 2])
            ->assertJsonFragment(['label' => 'Severe shellfish allergy', 'count' => 1, 'serious' => true])
            ->assertJsonFragment(['label' => 'Vegetarian', 'count' => 1])
            ->assertJsonFragment(['label' => 'Severe nut allergy', 'count' => 1, 'serious' => true])
            ->assertJsonMissing(['title' => 'Turn over room'])
            ->assertDontSee('Private Primary');
    }

    public function test_guide_projection_only_returns_linked_resource_assignments_reservations_and_tasks(): void
    {
        [$tenant, $property, $guideUser] = $this->tenantEnvironment(MembershipRole::Guide);
        $otherGuide = User::factory()->create();
        Membership::factory()->create([
            'user_id' => $otherGuide->id,
            'property_id' => $property->id,
            'role' => MembershipRole::Guide,
        ]);
        $program = Program::query()->create([
            'property_id' => $property->id,
            'name' => 'River Day',
            'currency' => $tenant->currency,
        ]);
        $arrival = CarbonImmutable::now($tenant->timezone)->startOfDay()->addHours(15)->utc();
        $linkedReservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'program_id' => $program->id,
            'confirmation_number' => 'GUIDE-LINKED',
            'status' => ReservationStatus::Confirmed,
            'starts_at' => $arrival,
            'ends_at' => $arrival->addDays(2),
        ]);
        $otherReservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'program_id' => $program->id,
            'confirmation_number' => 'GUIDE-OTHER',
            'status' => ReservationStatus::Confirmed,
            'starts_at' => $arrival,
            'ends_at' => $arrival->addDays(2),
        ]);
        $linkedResource = Resource::factory()->create([
            'property_id' => $property->id,
            'user_id' => $guideUser->id,
            'name' => 'Linked Guide',
            'category_id' => $this->category($property, 'guide')->id,
        ]);
        $otherResource = Resource::factory()->create([
            'property_id' => $property->id,
            'user_id' => $otherGuide->id,
            'name' => 'Other Guide',
            'category_id' => $this->category($property, 'guide')->id,
        ]);
        $occurrenceStart = CarbonImmutable::now($tenant->timezone)->addDay()->startOfDay()->addHours(8)->utc();
        $linkedOccurrence = ServiceOccurrence::query()->create([
            'program_id' => $program->id,
            'property_id' => $property->id,
            'starts_at' => $occurrenceStart,
            'ends_at' => $occurrenceStart->addHours(5),
            'capacity' => 8,
            'is_cancelled' => false,
        ]);
        $otherOccurrence = ServiceOccurrence::query()->create([
            'program_id' => $program->id,
            'property_id' => $property->id,
            'starts_at' => $occurrenceStart,
            'ends_at' => $occurrenceStart->addHours(5),
            'capacity' => 8,
            'is_cancelled' => false,
        ]);
        $linkedOccurrence->allocations()->create([
            'reservation_id' => $linkedReservation->id,
            'resource_id' => $linkedResource->id,
            'status' => AllocationStatus::Confirmed,
            'starts_at' => $linkedOccurrence->starts_at,
            'ends_at' => $linkedOccurrence->ends_at,
            'quantity' => 1,
        ]);
        $otherOccurrence->allocations()->create([
            'reservation_id' => $otherReservation->id,
            'resource_id' => $otherResource->id,
            'status' => AllocationStatus::Confirmed,
            'starts_at' => $otherOccurrence->starts_at,
            'ends_at' => $otherOccurrence->ends_at,
            'quantity' => 1,
        ]);
        OperationalTask::query()->create([
            'property_id' => $property->id,
            'reservation_id' => $linkedReservation->id,
            'assignee_id' => $guideUser->id,
            'title' => 'My assigned task',
            'status' => TaskStatus::Todo,
        ]);
        OperationalTask::query()->create([
            'property_id' => $property->id,
            'reservation_id' => $linkedReservation->id,
            'title' => 'Linked guide queue task',
            'status' => TaskStatus::Todo,
            'metadata' => ['assignee_role' => MembershipRole::Guide->value],
        ]);
        OperationalTask::query()->create([
            'property_id' => $property->id,
            'reservation_id' => $otherReservation->id,
            'title' => 'Other guide queue task',
            'status' => TaskStatus::Todo,
            'metadata' => ['assignee_role' => MembershipRole::Guide->value],
        ]);
        OperationalTask::query()->create([
            'property_id' => $property->id,
            'reservation_id' => $linkedReservation->id,
            'assignee_id' => $otherGuide->id,
            'title' => 'Other assigned task',
            'status' => TaskStatus::Todo,
        ]);

        $response = $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/operations');

        $response->assertOk()
            ->assertJsonPath('data.role_scope.role', 'guide')
            ->assertJsonPath('data.role_scope.visible_sections', ['tasks', 'arrivals', 'guide_assignments'])
            ->assertJsonPath('data.privacy.can_view_guest_identity', true)
            ->assertJsonPath('data.privacy.can_view_dietary_details', false)
            ->assertJsonCount(2, 'data.tasks')
            ->assertJsonCount(1, 'data.arrivals')
            ->assertJsonPath('data.arrivals.0.confirmation_number', 'GUIDE-LINKED')
            ->assertJsonCount(1, 'data.guide_assignments')
            ->assertJsonPath('data.guide_assignments.0.guide_resource_id', $linkedResource->id)
            ->assertJsonPath('data.guide_assignments.0.guide', 'Linked Guide')
            ->assertJsonPath('data.kitchen.available', false)
            ->assertJsonPath('data.housekeeping.available', false)
            ->assertJsonMissing(['confirmation_number' => 'GUIDE-OTHER'])
            ->assertJsonMissing(['guide' => 'Other Guide'])
            ->assertJsonMissing(['title' => 'Other guide queue task'])
            ->assertJsonMissing(['title' => 'Other assigned task']);
    }

    public function test_housekeeping_projection_only_returns_housekeeping_work_and_non_personal_turnover_counts(): void
    {
        [$tenant, $property] = $this->tenantEnvironment(MembershipRole::Housekeeping);
        $departure = CarbonImmutable::now($tenant->timezone)->startOfDay()->addHours(11)->utc();
        Reservation::factory()->create([
            'property_id' => $property->id,
            'status' => ReservationStatus::CheckedIn,
            'starts_at' => $departure->subDays(2),
            'ends_at' => $departure,
        ]);
        OperationalTask::query()->create([
            'property_id' => $property->id,
            'title' => 'Housekeeping turnover task',
            'status' => TaskStatus::Todo,
            'metadata' => ['team' => MembershipRole::Housekeeping->value],
        ]);
        OperationalTask::query()->create([
            'property_id' => $property->id,
            'title' => 'Kitchen prep task',
            'status' => TaskStatus::Todo,
            'metadata' => ['team' => MembershipRole::Kitchen->value],
        ]);

        $response = $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/operations');

        $response->assertOk()
            ->assertJsonPath('data.role_scope.role', 'housekeeping')
            ->assertJsonPath('data.role_scope.visible_sections', ['tasks', 'arrivals', 'housekeeping'])
            ->assertJsonPath('data.privacy.can_view_guest_identity', false)
            ->assertJsonPath('data.privacy.can_view_dietary_details', false)
            ->assertJsonPath('data.privacy.restricted_fields', ['arrivals.guest_name', 'arrivals.dietary'])
            ->assertJsonCount(1, 'data.tasks')
            ->assertJsonPath('data.tasks.0.title', 'Housekeeping turnover task')
            ->assertJsonPath('data.kitchen.available', false)
            ->assertJsonPath('data.kitchen.restrictions', [])
            ->assertJsonPath('data.housekeeping.available', true)
            ->assertJsonPath('data.housekeeping.turnovers', 1)
            ->assertJsonPath('data.guide_assignments', [])
            ->assertJsonMissing(['title' => 'Kitchen prep task']);
    }
}
