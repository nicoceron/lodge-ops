<?php

namespace Tests\Feature;

use App\Enums\AllocationStatus;
use App\Enums\MembershipRole;
use App\Enums\ReservationStatus;
use App\Models\Guest;
use App\Models\Membership;
use App\Models\Program;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use App\Models\Resource;
use App\Models\ServiceOccurrence;
use App\Models\User;
use App\Services\BookingQuoteService;
use App\Services\ReservationService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class OperationalBookingCoreTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_staff_api_exposes_properties_program_requirements_and_reservation_program(): void
    {
        [$tenant, $property, $user] = $this->tenantEnvironment();
        $program = $this->program($property->id, ['display_color' => '#0F766E', 'requires_accommodation' => true]);
        $program->requirements()->create([
            'resource_category_id' => $this->category($property, 'guide')->id,
            'minimum_quantity' => 1,
            'guests_per_resource' => 4,
            'capabilities' => ['first aid'],
            'languages' => ['spanish'],
        ]);
        $guest = Guest::factory()->create();
        $roomCategory = $this->category($property, 'room');
        $room = Resource::factory()->create(['property_id' => $property->id, 'category_id' => $roomCategory->id]);
        $ratePlan = RatePlan::query()->create([
            'property_id' => $property->id,
            'name' => 'Operational API',
            'currency' => 'USD',
            'maximum_occupancy' => 4,
        ]);
        RateRule::query()->create([
            'rate_plan_id' => $ratePlan->id,
            'resource_category_id' => $roomCategory->id,
            'amount_minor' => 10_000,
        ]);
        $quote = app(BookingQuoteService::class)->create([
            'property_id' => $property->id,
            'rate_plan_id' => $ratePlan->id,
            'resource_category_id' => $roomCategory->id,
            'resource_id' => $room->id,
            'program_id' => $program->id,
            'starts_at' => '2026-11-01T15:00:00Z',
            'ends_at' => '2026-11-04T11:00:00Z',
            'adults' => 2,
            'children' => 0,
        ]);

        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/properties?per_page=100')
            ->assertOk()
            ->assertJsonPath('data.0.id', $property->id)
            ->assertJsonPath('data.0.timezone', $property->timezone);

        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson("/api/v1/programs?property_id={$property->id}&per_page=100")
            ->assertOk()
            ->assertJsonPath('data.0.display_color', '#0F766E')
            ->assertJsonPath('data.0.requires_accommodation', true)
            ->assertJsonPath('data.0.requirements.0.category_slug', 'guide')
            ->assertJsonPath('data.0.requirements.0.quantity', 1)
            ->assertJsonPath('data.0.requirements.0.guests_per_resource', 4);

        $response = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/reservations', [
            'quote_id' => $quote->id,
            'primary_guest_id' => $guest->id,
        ])->assertCreated()
            ->assertJsonPath('data.program_id', $program->id)
            ->assertJsonPath('data.program.id', $program->id)
            ->assertJsonPath('data.program.name', $program->name);

        $this->assertDatabaseHas('reservation_guests', [
            'reservation_id' => $response->json('data.id'),
            'guest_id' => $guest->id,
            'role' => 'primary',
        ]);
    }

    public function test_operational_crud_supports_cross_program_activities_allocation_release_and_resource_blocks(): void
    {
        [$tenant, $property] = $this->tenantEnvironment();
        $package = $this->program($property->id, ['name' => 'Lodge package']);
        $activity = $this->program($property->id, ['name' => 'Horseback outing']);
        $room = Resource::factory()->create([
            'property_id' => $property->id,
            'capacity' => 2,
            'is_buyout' => false,
        ]);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'program_id' => $package->id,
            'starts_at' => CarbonImmutable::parse('2026-11-01T15:00:00Z'),
            'ends_at' => CarbonImmutable::parse('2026-11-04T11:00:00Z'),
        ]);
        $headers = ['X-Tenant-ID' => $tenant->id, 'Idempotency-Key' => 'occurrence-create-0001'];

        $occurrenceId = $this->withHeaders($headers)->postJson('/api/v1/service-occurrences', [
            'program_id' => $activity->id,
            'property_id' => $property->id,
            'starts_at' => '2026-11-02T13:00:00Z',
            'ends_at' => '2026-11-02T16:00:00Z',
            'capacity' => 8,
            'meeting_point' => 'Stable',
        ])->assertCreated()->assertJsonPath('data.program.id', $activity->id)->json('data.id');

        $allocationId = $this->withHeaders([
            'X-Tenant-ID' => $tenant->id,
            'Idempotency-Key' => 'allocation-create-0001',
        ])->postJson("/api/v1/reservations/{$reservation->id}/allocations", [
            'service_occurrence_id' => $occurrenceId,
            'starts_at' => '2026-11-02T13:00:00Z',
            'ends_at' => '2026-11-02T16:00:00Z',
            'quantity' => 2,
        ])->assertCreated()->assertJsonPath('data.service_occurrence.program_id', $activity->id)->json('data.id');

        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->putJson("/api/v1/reservations/{$reservation->id}/allocations/{$allocationId}", ['quantity' => 3])
            ->assertOk()->assertJsonPath('data.quantity', 3);
        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->deleteJson("/api/v1/reservations/{$reservation->id}/allocations/{$allocationId}")
            ->assertNoContent();
        $this->assertDatabaseHas('allocations', ['id' => $allocationId, 'status' => AllocationStatus::Released->value]);

        $blockId = $this->withHeaders([
            'X-Tenant-ID' => $tenant->id,
            'Idempotency-Key' => 'block-create-0001',
        ])->postJson('/api/v1/resource-blocks', [
            'resource_id' => $room->id,
            'starts_at' => '2026-12-01T00:00:00Z',
            'ends_at' => '2026-12-02T00:00:00Z',
            'reason' => 'Maintenance',
        ])->assertCreated()->assertJsonPath('data.resource.id', $room->id)->json('data.id');
        $this->withHeader('X-Tenant-ID', $tenant->id)->putJson("/api/v1/resource-blocks/{$blockId}", [
            'reason' => 'Deep maintenance',
        ])->assertOk()->assertJsonPath('data.reason', 'Deep maintenance');
        $this->withHeader('X-Tenant-ID', $tenant->id)->deleteJson("/api/v1/resource-blocks/{$blockId}")->assertNoContent();

        $this->withHeader('X-Tenant-ID', $tenant->id)->deleteJson("/api/v1/service-occurrences/{$occurrenceId}")->assertNoContent();
        $this->assertDatabaseHas('service_occurrences', ['id' => $occurrenceId, 'is_cancelled' => true]);
    }

    public function test_cancelling_an_occurrence_releases_every_active_allocation(): void
    {
        [$tenant, $property] = $this->tenantEnvironment();
        $program = $this->program($property->id);
        $occurrence = ServiceOccurrence::query()->create([
            'program_id' => $program->id,
            'property_id' => $property->id,
            'starts_at' => '2026-11-02T13:00:00Z',
            'ends_at' => '2026-11-02T16:00:00Z',
            'capacity' => 8,
        ]);
        $allocations = collect([AllocationStatus::Tentative, AllocationStatus::Confirmed, AllocationStatus::Released])
            ->map(function (AllocationStatus $status) use ($occurrence, $property) {
                $reservation = Reservation::factory()->create(['property_id' => $property->id]);

                return $reservation->allocations()->create([
                    'service_occurrence_id' => $occurrence->id,
                    'status' => $status,
                    'starts_at' => $occurrence->starts_at,
                    'ends_at' => $occurrence->ends_at,
                    'quantity' => 1,
                ]);
            });

        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->deleteJson("/api/v1/service-occurrences/{$occurrence->id}")
            ->assertNoContent();

        $allocations->each(fn ($allocation) => $this->assertSame(AllocationStatus::Released, $allocation->fresh()->status));
    }

    public function test_confirmation_enforces_program_ratio_and_full_stay_room_then_provisions_tasks_and_payment_schedule_once(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-01T12:00:00Z'));
        [, $property] = $this->tenantEnvironment(authenticate: false);
        $program = $this->program($property->id, ['requires_accommodation' => true]);
        $program->requirements()->create([
            'resource_category_id' => $this->category($property, 'guide')->id,
            'minimum_quantity' => 1,
            'guests_per_resource' => 2,
            'capabilities' => ['first aid'],
            'languages' => ['spanish'],
        ]);
        $template = $program->taskTemplates()->create([
            'title' => 'Prepare guest welcome kit',
            'assignee_role' => MembershipRole::Operations->value,
            'priority' => 'high',
            'due_offset_minutes' => -1440,
            'is_active' => true,
        ]);
        $room = Resource::factory()->create(['property_id' => $property->id, 'capacity' => 2]);
        $guide = Resource::factory()->create([
            'property_id' => $property->id,
            'category_id' => $this->category($property, 'guide')->id,
            'capacity' => 3,
            'attributes' => ['capabilities' => ['first aid'], 'languages' => ['spanish', 'english']],
        ]);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'program_id' => $program->id,
            'status' => ReservationStatus::Draft,
            'starts_at' => now()->addDays(60),
            'ends_at' => now()->addDays(64),
            'adults' => 5,
            'children' => 0,
            'subtotal_minor' => 10001,
            'tax_minor' => 0,
            'total_minor' => 10001,
            'currency' => 'USD',
        ]);
        $reservation->allocations()->create([
            'resource_id' => $room->id,
            'status' => AllocationStatus::Tentative,
            'starts_at' => $reservation->starts_at,
            'ends_at' => $reservation->ends_at,
            'quantity' => 1,
        ]);
        $reservation->allocations()->create([
            'resource_id' => $guide->id,
            'status' => AllocationStatus::Tentative,
            'starts_at' => $reservation->starts_at,
            'ends_at' => $reservation->ends_at,
            'quantity' => 3,
        ]);

        $service = app(ReservationService::class);
        $confirmed = $service->confirm($reservation);
        $service->confirm($confirmed);

        $this->assertSame(ReservationStatus::Confirmed, $confirmed->status);
        $this->assertDatabaseCount('operational_tasks', 1);
        $this->assertDatabaseHas('operational_tasks', [
            'reservation_id' => $reservation->id,
            'program_task_template_id' => $template->id,
            'title' => 'Prepare guest welcome kit',
        ]);
        $this->assertDatabaseCount('deposits', 2);
        $this->assertDatabaseHas('deposits', [
            'reservation_id' => $reservation->id,
            'schedule_type' => 'deposit_50',
            'amount_minor' => 5001,
        ]);
        $this->assertDatabaseHas('deposits', [
            'reservation_id' => $reservation->id,
            'schedule_type' => 'balance',
            'amount_minor' => 5000,
        ]);
        $this->assertDatabaseCount('reservation_status_histories', 1);
        $this->assertDatabaseHas('reservation_status_histories', [
            'reservation_id' => $reservation->id,
            'from_status' => 'draft',
            'to_status' => 'confirmed',
        ]);
    }

    public function test_a_simple_stay_cannot_be_held_without_a_room_covering_the_full_stay(): void
    {
        [, $property] = $this->tenantEnvironment(authenticate: false);
        $room = Resource::factory()->create(['property_id' => $property->id]);
        $reservation = Reservation::factory()->create(['property_id' => $property->id, 'program_id' => null]);
        $reservation->allocations()->create([
            'resource_id' => $room->id,
            'status' => AllocationStatus::Tentative,
            'starts_at' => $reservation->starts_at->addHour(),
            'ends_at' => $reservation->ends_at,
            'quantity' => 1,
        ]);

        $this->expectExceptionMessage('requires a stay-place allocation covering the full stay');
        app(ReservationService::class)->transition($reservation, ReservationStatus::Hold);
    }

    public function test_guest_history_combines_primary_and_companion_stays(): void
    {
        [$tenant, $property] = $this->tenantEnvironment();
        $guest = Guest::factory()->create();
        $primaryStay = Reservation::factory()->create([
            'property_id' => $property->id,
            'primary_guest_id' => $guest->id,
            'status' => ReservationStatus::CheckedOut,
            'currency' => $tenant->currency,
            'total_minor' => 12000,
        ]);
        $companionStay = Reservation::factory()->create([
            'property_id' => $property->id,
            'status' => ReservationStatus::Confirmed,
            'currency' => $tenant->currency,
            'total_minor' => 8000,
            'starts_at' => $primaryStay->starts_at->subMonth(),
            'ends_at' => $primaryStay->ends_at->subMonth(),
        ]);
        ReservationGuest::query()->create([
            'reservation_id' => $primaryStay->id,
            'guest_id' => $guest->id,
            'role' => 'primary',
        ]);
        ReservationGuest::query()->create([
            'reservation_id' => $companionStay->id,
            'guest_id' => $guest->id,
            'role' => 'guest',
        ]);

        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson("/api/v1/guests/{$guest->id}/history")
            ->assertOk()
            ->assertJsonCount(2, 'data.reservations')
            ->assertJsonPath('data.stats.stays', 2)
            ->assertJsonPath('data.stats.lifetime_value_minor', 20000)
            ->assertJsonPath('data.stats.currency', $tenant->currency);
    }

    public function test_a_guide_can_only_block_the_resource_linked_to_their_user(): void
    {
        [$tenant, $property] = $this->tenantEnvironment(authenticate: false);
        $guideUser = User::factory()->create();
        $guideMembership = Membership::factory()->create([
            'user_id' => $guideUser->id,
            'property_id' => $property->id,
            'role' => MembershipRole::Guide,
        ]);
        $ownGuide = Resource::factory()->create([
            'property_id' => $property->id,
            'category_id' => $this->category($property, 'guide')->id,
            'user_id' => $guideUser->id,
        ]);
        $otherGuide = Resource::factory()->create([
            'property_id' => $property->id,
            'category_id' => $this->category($property, 'guide')->id,
            'user_id' => null,
        ]);
        app(TenantContext::class)->set($tenant, $guideMembership);
        Sanctum::actingAs($guideUser);

        $payload = [
            'starts_at' => '2026-12-01T00:00:00Z',
            'ends_at' => '2026-12-02T00:00:00Z',
            'reason' => 'Personal leave',
        ];
        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/resource-blocks', [
            ...$payload,
            'resource_id' => $ownGuide->id,
        ])->assertCreated();
        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/resource-blocks', [
            ...$payload,
            'resource_id' => $otherGuide->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('resource_id');
    }

    private function program(string $propertyId, array $attributes = []): Program
    {
        return Program::query()->create([
            'property_id' => $propertyId,
            'name' => 'Patagonia Experience '.fake()->unique()->word(),
            'description' => 'A configured lodge experience.',
            'display_color' => '#2563EB',
            'requires_accommodation' => false,
            'default_duration_minutes' => 120,
            'capacity' => 8,
            'price_minor' => 10000,
            'currency' => 'USD',
            'is_active' => true,
            ...$attributes,
        ]);
    }
}
