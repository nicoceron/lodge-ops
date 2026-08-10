<?php

namespace Tests\Feature;

use App\Enums\AllocationStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\AllocationConflictException;
use App\Models\Reservation;
use App\Models\Resource;
use App\Services\ReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class AllocationConflictTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_overlapping_confirmed_allocation_is_rejected_and_transaction_rolls_back(): void
    {
        [, $property] = $this->tenantEnvironment(authenticate: false);
        $resource = Resource::factory()->create(['property_id' => $property->id]);
        $start = CarbonImmutable::parse('2026-09-01T15:00:00Z');
        $existing = Reservation::factory()->create([
            'property_id' => $property->id,
            'status' => ReservationStatus::Confirmed,
            'starts_at' => $start,
            'ends_at' => $start->addDays(2),
        ]);
        $existing->allocations()->create([
            'resource_id' => $resource->id,
            'status' => AllocationStatus::Confirmed,
            'starts_at' => $start,
            'ends_at' => $start->addDays(2),
            'quantity' => 1,
        ]);
        $candidate = Reservation::factory()->create([
            'property_id' => $property->id,
            'status' => ReservationStatus::Draft,
            'starts_at' => $start->addDay(),
            'ends_at' => $start->addDays(3),
        ]);
        $candidate->allocations()->create([
            'resource_id' => $resource->id,
            'status' => AllocationStatus::Tentative,
            'starts_at' => $start->addDay(),
            'ends_at' => $start->addDays(3),
            'quantity' => 1,
        ]);

        try {
            app(ReservationService::class)->confirm($candidate);
            $this->fail('Expected an allocation conflict.');
        } catch (AllocationConflictException $exception) {
            $this->assertSame($resource->id, $exception->resourceId);
        }

        $this->assertSame(ReservationStatus::Draft, $candidate->fresh()->status);
        $this->assertSame(AllocationStatus::Tentative, $candidate->allocations()->first()->status);
    }

    public function test_touching_half_open_intervals_do_not_overlap(): void
    {
        [, $property] = $this->tenantEnvironment(authenticate: false);
        $resource = Resource::factory()->create(['property_id' => $property->id]);
        $start = CarbonImmutable::parse('2026-09-01T15:00:00Z');
        $existing = Reservation::factory()->create(['property_id' => $property->id, 'status' => ReservationStatus::Confirmed]);
        $existing->allocations()->create([
            'resource_id' => $resource->id,
            'status' => AllocationStatus::Confirmed,
            'starts_at' => $start,
            'ends_at' => $start->addDay(),
            'quantity' => 1,
        ]);
        $candidate = Reservation::factory()->create(['property_id' => $property->id, 'status' => ReservationStatus::Draft]);
        $candidate->allocations()->create([
            'resource_id' => $resource->id,
            'status' => AllocationStatus::Tentative,
            'starts_at' => $start->addDay(),
            'ends_at' => $start->addDays(2),
            'quantity' => 1,
        ]);

        $confirmed = app(ReservationService::class)->confirm($candidate);

        $this->assertSame(ReservationStatus::Confirmed, $confirmed->status);
        $this->assertSame(AllocationStatus::Confirmed, $confirmed->allocations->first()->status);
    }
}
