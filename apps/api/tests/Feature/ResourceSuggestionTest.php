<?php

namespace Tests\Feature;

use App\Enums\AllocationStatus;
use App\Enums\ReservationStatus;
use App\Enums\ResourceType;
use App\Exceptions\AllocationConflictException;
use App\Models\Allocation;
use App\Models\Reservation;
use App\Models\Resource;
use App\Services\AvailabilityService;
use App\Services\ResourceSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class ResourceSuggestionTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_suggestions_require_qualifications_availability_and_fair_assignment_order(): void
    {
        [, $property] = $this->tenantEnvironment();
        $preferred = Resource::factory()->create([
            'property_id' => $property->id,
            'name' => 'Ana Guide',
            'type' => ResourceType::Guide,
            'attributes' => ['capabilities' => ['fly fishing'], 'languages' => ['Spanish', 'English']],
        ]);
        Resource::factory()->create([
            'property_id' => $property->id,
            'name' => 'Unqualified Guide',
            'type' => ResourceType::Guide,
            'attributes' => ['capabilities' => ['hunting'], 'languages' => ['English']],
        ]);
        $busy = Resource::factory()->create([
            'property_id' => $property->id,
            'name' => 'Busy Guide',
            'type' => ResourceType::Guide,
            'attributes' => ['capabilities' => ['fly fishing'], 'languages' => ['Spanish']],
        ]);
        $reservation = Reservation::factory()->create(['property_id' => $property->id]);
        Allocation::query()->create([
            'reservation_id' => $reservation->id,
            'resource_id' => $busy->id,
            'status' => AllocationStatus::Confirmed,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(2),
            'quantity' => 1,
        ]);

        $suggestions = app(ResourceSuggestionService::class)->suggest(
            ResourceType::Guide,
            now()->addDay(),
            now()->addDays(2),
            capabilities: ['fly fishing'],
            languages: ['Spanish'],
        );

        $this->assertSame([$preferred->id], $suggestions->pluck('id')->all());
        $this->assertStringContainsString('fly fishing', implode(' ', $suggestions->first()['reasons']));
    }

    public function test_property_buyout_conflicts_with_an_existing_resource_allocation(): void
    {
        [, $property] = $this->tenantEnvironment();
        $room = Resource::factory()->create(['property_id' => $property->id, 'type' => ResourceType::Room]);
        $buyout = Resource::factory()->create([
            'property_id' => $property->id,
            'type' => ResourceType::Venue,
            'attributes' => ['buyout' => true],
        ]);
        $reservationA = Reservation::factory()->create(['property_id' => $property->id]);
        $reservationB = Reservation::factory()->create(['property_id' => $property->id]);
        Allocation::query()->create([
            'reservation_id' => $reservationA->id,
            'resource_id' => $room->id,
            'status' => AllocationStatus::Confirmed,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(2),
            'quantity' => 1,
        ]);
        $candidate = new Allocation([
            'reservation_id' => $reservationB->id,
            'resource_id' => $buyout->id,
            'status' => AllocationStatus::Tentative,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(2),
            'quantity' => 1,
        ]);

        $this->expectException(AllocationConflictException::class);
        app(AvailabilityService::class)->assertAvailable($candidate);
    }

    public function test_resource_allocation_conflicts_with_an_existing_property_buyout(): void
    {
        [, $property] = $this->tenantEnvironment();
        $room = Resource::factory()->create(['property_id' => $property->id, 'type' => ResourceType::Room]);
        $buyout = Resource::factory()->create([
            'property_id' => $property->id,
            'type' => ResourceType::Venue,
            'attributes' => ['buyout' => true],
        ]);
        $reservationA = Reservation::factory()->create(['property_id' => $property->id]);
        $reservationB = Reservation::factory()->create(['property_id' => $property->id]);
        Allocation::query()->create([
            'reservation_id' => $reservationA->id,
            'resource_id' => $buyout->id,
            'status' => AllocationStatus::Confirmed,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(2),
            'quantity' => 1,
        ]);
        $candidate = new Allocation([
            'reservation_id' => $reservationB->id,
            'resource_id' => $room->id,
            'status' => AllocationStatus::Tentative,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(2),
            'quantity' => 1,
        ]);

        $this->expectException(AllocationConflictException::class);
        app(AvailabilityService::class)->assertAvailable($candidate);
    }

    public function test_suggestions_respect_active_holds_and_property_buyouts(): void
    {
        [, $property] = $this->tenantEnvironment();
        $guide = Resource::factory()->create(['property_id' => $property->id, 'type' => ResourceType::Guide]);
        $buyout = Resource::factory()->create([
            'property_id' => $property->id,
            'type' => ResourceType::Venue,
            'attributes' => ['buyout' => true],
        ]);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'status' => ReservationStatus::Hold,
            'hold_expires_at' => now()->addHour(),
        ]);
        Allocation::query()->create([
            'reservation_id' => $reservation->id,
            'resource_id' => $buyout->id,
            'status' => AllocationStatus::Tentative,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(2),
            'quantity' => 1,
        ]);

        $suggestions = app(ResourceSuggestionService::class)->suggest(
            ResourceType::Guide,
            now()->addDay(),
            now()->addDays(2),
        );

        $this->assertNotContains($guide->id, $suggestions->pluck('id')->all());
    }

    public function test_suggestions_honor_the_buyout_column_used_by_the_resource_model(): void
    {
        [, $property] = $this->tenantEnvironment();
        $guide = Resource::factory()->create(['property_id' => $property->id, 'type' => ResourceType::Guide]);
        $buyout = Resource::factory()->create([
            'property_id' => $property->id,
            'type' => ResourceType::Venue,
            'is_buyout' => true,
            'attributes' => [],
        ]);
        $reservation = Reservation::factory()->create(['property_id' => $property->id]);
        Allocation::query()->create([
            'reservation_id' => $reservation->id,
            'resource_id' => $buyout->id,
            'status' => AllocationStatus::Confirmed,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(2),
            'quantity' => 1,
        ]);

        $suggestions = app(ResourceSuggestionService::class)->suggest(
            ResourceType::Guide,
            now()->addDay(),
            now()->addDays(2),
        );

        $this->assertNotContains($guide->id, $suggestions->pluck('id')->all());
    }
}
