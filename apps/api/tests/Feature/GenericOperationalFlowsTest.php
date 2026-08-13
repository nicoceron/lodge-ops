<?php

namespace Tests\Feature;

use App\Enums\AllocationStatus;
use App\Enums\FolioLineType;
use App\Enums\FolioStatus;
use App\Enums\HousekeepingStatus;
use App\Enums\MembershipRole;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Enums\ResourceKind;
use App\Exceptions\AllocationConflictException;
use App\Exceptions\CommercialWorkflowException;
use App\Models\Allocation;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\ResourceBlock;
use App\Services\AllocationWorkflowService;
use App\Services\CalendarFeedService;
use App\Services\FolioService;
use App\Services\ReservationService;
use App\Services\ResourceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class GenericOperationalFlowsTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_shared_catalog_only_creates_categories_explicitly_selected_for_a_property(): void
    {
        [$tenant] = $this->tenantEnvironment();
        $property = Property::factory()->for($tenant)->create();

        app(ResourceCatalog::class)->ensure($property, [
            ['kind' => ResourceKind::Place, 'slug' => 'chalet', 'name' => 'Chalet', 'counts_as_stay' => true],
            ['kind' => ResourceKind::Crew, 'slug' => 'instructor', 'name' => 'Instructor'],
        ]);

        $this->assertSame(['chalet', 'instructor'], $property->resourceCategories()->pluck('slug')->sort()->values()->all());
        $this->assertDatabaseMissing('resource_categories', ['property_id' => $property->id, 'slug' => 'horse']);
    }

    public function test_category_request_can_hold_capacity_before_an_exact_instance_is_assigned(): void
    {
        [, $property] = $this->tenantEnvironment();
        $category = $this->category($property, 'room');
        Resource::factory()->count(2)->create([
            'property_id' => $property->id,
            'category_id' => $category->id,
            'capacity' => 1,
        ]);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'status' => ReservationStatus::Draft,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(3),
        ]);
        $allocation = app(AllocationWorkflowService::class)->create($reservation, [
            'requested_category_id' => $category->id,
            'starts_at' => $reservation->starts_at,
            'ends_at' => $reservation->ends_at,
            'quantity' => 1,
        ]);

        app(ReservationService::class)->transition($reservation, ReservationStatus::Hold, 60);

        $this->assertNull($allocation->resource_id);
        $this->assertSame($category->id, $allocation->requested_category_id);
        $this->assertSame(ReservationStatus::Hold, $reservation->refresh()->status);

        $second = Reservation::factory()->create([
            'property_id' => $property->id,
            'status' => ReservationStatus::Draft,
            'starts_at' => $reservation->starts_at,
            'ends_at' => $reservation->ends_at,
        ]);
        app(AllocationWorkflowService::class)->create($second, [
            'requested_category_id' => $category->id,
            'starts_at' => $second->starts_at,
            'ends_at' => $second->ends_at,
            'quantity' => 2,
        ]);

        $this->expectException(AllocationConflictException::class);
        app(ReservationService::class)->transition($second, ReservationStatus::Hold, 60);
    }

    public function test_an_unassigned_exclusive_category_request_blocks_the_whole_property(): void
    {
        [, $property] = $this->tenantEnvironment();
        $exclusiveCategory = $this->category($property, 'venue');
        $placeCategory = $this->category($property, 'room');
        $exclusiveCategory->update(['counts_as_stay' => true]);
        Resource::factory()->create([
            'property_id' => $property->id,
            'category_id' => $exclusiveCategory->id,
            'is_buyout' => true,
            'capacity' => 1,
        ]);
        $place = Resource::factory()->create([
            'property_id' => $property->id,
            'category_id' => $placeCategory->id,
            'capacity' => 1,
        ]);
        $exclusive = Reservation::factory()->create([
            'property_id' => $property->id,
            'status' => ReservationStatus::Draft,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(3),
        ]);
        app(AllocationWorkflowService::class)->create($exclusive, [
            'requested_category_id' => $exclusiveCategory->id,
            'starts_at' => $exclusive->starts_at,
            'ends_at' => $exclusive->ends_at,
            'quantity' => 1,
        ]);
        $this->assertTrue($exclusive->fresh()->allocations()->firstOrFail()->requestedCategory()->firstOrFail()->counts_as_stay);
        app(ReservationService::class)->transition($exclusive, ReservationStatus::Hold, 60);

        $ordinary = Reservation::factory()->create([
            'property_id' => $property->id,
            'status' => ReservationStatus::Draft,
            'starts_at' => $exclusive->starts_at,
            'ends_at' => $exclusive->ends_at,
        ]);
        app(AllocationWorkflowService::class)->create($ordinary, [
            'resource_id' => $place->id,
            'starts_at' => $ordinary->starts_at,
            'ends_at' => $ordinary->ends_at,
            'quantity' => 1,
        ]);
        $this->assertTrue($ordinary->fresh()->allocations()->firstOrFail()->requestedCategory()->firstOrFail()->counts_as_stay);

        $this->expectException(AllocationConflictException::class);
        app(ReservationService::class)->transition($ordinary, ReservationStatus::Hold, 60);
    }

    public function test_folio_tracks_net_tax_gross_and_must_be_settled_before_close(): void
    {
        [, $property, $user] = $this->tenantEnvironment();
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'status' => ReservationStatus::CheckedOut,
            'subtotal_minor' => 10_000,
            'tax_minor' => 1_000,
            'total_minor' => 11_000,
        ]);
        $line = app(FolioService::class)->append(
            $reservation,
            FolioLineType::Charge,
            'Late transfer',
            1000,
            5_000,
            $user->id,
            taxAmountMinor: 500,
        );
        $payment = Payment::query()->create([
            'reservation_id' => $reservation->id,
            'status' => PaymentStatus::Succeeded,
            'method' => 'card',
            'currency' => $reservation->currency,
            'amount_minor' => 16_500,
            'processed_at' => now(),
        ]);
        app(FolioService::class)->postPayment($payment, $user->id);

        $this->assertSame(5_000, $line->net_amount_minor);
        $this->assertSame(500, $line->tax_amount_minor);
        $this->assertSame(5_500, $line->gross_amount_minor);
        $this->assertSame(0, app(FolioService::class)->summary($reservation)['balance_minor']);

        $closed = app(FolioService::class)->close($reservation, $user->id);
        $this->assertSame(FolioStatus::Closed, $closed->folio_status);

        try {
            app(FolioService::class)->append($closed, FolioLineType::Charge, 'Blocked charge', 1000, 100, $user->id);
            $this->fail('A closed folio accepted a new entry.');
        } catch (CommercialWorkflowException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(FolioStatus::Open, app(FolioService::class)->reopen($closed)->folio_status);
    }

    public function test_reservation_note_timeline_is_append_only_and_available_through_the_api(): void
    {
        [$tenant, $property] = $this->tenantEnvironment();
        $reservation = Reservation::factory()->create(['property_id' => $property->id]);

        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->postJson("/api/v1/reservations/{$reservation->id}/notes", [
                'kind' => 'guest_request',
                'body' => 'Needs a low-allergen pillow.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'guest_request');

        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->getJson("/api/v1/reservations/{$reservation->id}")
            ->assertOk()
            ->assertJsonPath('data.note_timeline.0.body', 'Needs a low-allergen pillow.');
    }

    public function test_housekeeping_can_update_place_state_without_configuration_access(): void
    {
        [$tenant, $property] = $this->tenantEnvironment(MembershipRole::Housekeeping);
        $place = Resource::factory()->create([
            'property_id' => $property->id,
            'category_id' => $this->category($property, 'room')->id,
        ]);

        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->patchJson("/api/v1/resources/{$place->id}/housekeeping", ['status' => HousekeepingStatus::Dirty->value])
            ->assertOk()
            ->assertJsonPath('data.housekeeping_status', 'dirty');

        $this->assertSame(HousekeepingStatus::Dirty, $place->refresh()->housekeeping_status);
        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/resources', [
            'property_id' => $property->id,
            'name' => 'Forbidden place',
            'code' => 'FORBIDDEN-PLACE',
            'category_id' => $place->category_id,
            'capacity' => 1,
        ])->assertForbidden();
    }

    public function test_private_channel_feed_exports_allocations_and_blocks_without_guest_identity(): void
    {
        [, $property] = $this->tenantEnvironment();
        $guest = Guest::factory()->create(['first_name' => 'Secret', 'last_name' => 'Guest']);
        $resource = Resource::factory()->create([
            'property_id' => $property->id,
            'category_id' => $this->category($property, 'room')->id,
        ]);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'primary_guest_id' => $guest->id,
            'status' => ReservationStatus::Confirmed,
            'confirmation_number' => 'RSV-CHANNEL-001',
        ]);
        Allocation::query()->create([
            'reservation_id' => $reservation->id,
            'resource_id' => $resource->id,
            'status' => AllocationStatus::Confirmed,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(2),
            'quantity' => 1,
        ]);
        ResourceBlock::query()->create([
            'resource_id' => $resource->id,
            'starts_at' => now()->addDays(4),
            'ends_at' => now()->addDays(5),
            'reason' => 'Maintenance',
        ]);
        $feed = app(CalendarFeedService::class)->create($property->id, $resource->id, 'Channel export');

        $response = $this->get(parse_url(app(CalendarFeedService::class)->url($feed), PHP_URL_PATH));

        $response->assertOk()->assertHeader('content-type', 'text/calendar; charset=utf-8');
        $response->assertSee('BEGIN:VCALENDAR', false)
            ->assertSee('RSV-CHANNEL-001', false)
            ->assertSee('Maintenance', false)
            ->assertDontSee('Secret Guest', false);
    }
}
