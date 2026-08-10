<?php

namespace Tests\Feature;

use App\Enums\AllocationStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\AllocationConflictException;
use App\Exceptions\InvalidStatusTransitionException;
use App\Models\Reservation;
use App\Models\Resource;
use App\Services\ReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class ReservationStatusTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_invalid_transition_is_rejected(): void
    {
        [, $property] = $this->tenantEnvironment(authenticate: false);
        $reservation = Reservation::factory()->create(['property_id' => $property->id, 'status' => ReservationStatus::Draft]);

        $this->expectException(InvalidStatusTransitionException::class);
        app(ReservationService::class)->transition($reservation, ReservationStatus::CheckedIn);
    }

    public function test_cancellation_releases_allocations(): void
    {
        [, $property] = $this->tenantEnvironment(authenticate: false);
        $resource = Resource::factory()->create(['property_id' => $property->id]);
        $reservation = Reservation::factory()->create(['property_id' => $property->id, 'status' => ReservationStatus::Confirmed]);
        $allocation = $reservation->allocations()->create([
            'resource_id' => $resource->id,
            'status' => AllocationStatus::Confirmed,
            'starts_at' => now(),
            'ends_at' => now()->addDay(),
            'quantity' => 1,
        ]);

        app(ReservationService::class)->transition($reservation, ReservationStatus::Cancelled);

        $this->assertSame(ReservationStatus::Cancelled, $reservation->fresh()->status);
        $this->assertSame(AllocationStatus::Released, $allocation->fresh()->status);
    }

    public function test_a_hold_reserves_capacity_and_expiry_releases_it_idempotently(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-01T12:00:00Z'));
        [, $property] = $this->tenantEnvironment(authenticate: false);
        $resource = Resource::factory()->create(['property_id' => $property->id]);
        $held = Reservation::factory()->create(['property_id' => $property->id, 'status' => ReservationStatus::Draft]);
        $heldAllocation = $held->allocations()->create([
            'resource_id' => $resource->id,
            'status' => AllocationStatus::Tentative,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(2),
            'quantity' => 1,
        ]);
        $candidate = Reservation::factory()->create(['property_id' => $property->id, 'status' => ReservationStatus::Draft]);
        $candidate->allocations()->create([
            'resource_id' => $resource->id,
            'status' => AllocationStatus::Tentative,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(2),
            'quantity' => 1,
        ]);
        $service = app(ReservationService::class);

        $hold = $service->transition($held, ReservationStatus::Hold, 10);
        $this->assertSame(ReservationStatus::Hold, $hold->status);
        $this->assertTrue($hold->hold_expires_at->equalTo(now()->addMinutes(10)));

        try {
            $service->confirm($candidate);
            $this->fail('An active hold must reserve its allocation capacity.');
        } catch (AllocationConflictException $exception) {
            $this->assertSame($resource->id, $exception->resourceId);
        }

        $this->travel(11)->minutes();
        $this->artisan('reservation-holds:expire')
            ->expectsOutput('Expired 1 reservation hold(s).')
            ->assertSuccessful();
        $this->assertSame(0, $service->expireDueHolds());
        $this->assertSame(ReservationStatus::Draft, $held->fresh()->status);
        $this->assertNull($held->fresh()->hold_expires_at);
        $this->assertSame(AllocationStatus::Released, $heldAllocation->fresh()->status);

        $this->assertSame(ReservationStatus::Confirmed, $service->confirm($candidate)->status);
    }

    public function test_a_hold_cannot_be_created_over_confirmed_capacity(): void
    {
        [, $property] = $this->tenantEnvironment(authenticate: false);
        $resource = Resource::factory()->create(['property_id' => $property->id]);
        $confirmed = Reservation::factory()->create(['property_id' => $property->id, 'status' => ReservationStatus::Confirmed]);
        $confirmed->allocations()->create([
            'resource_id' => $resource->id,
            'status' => AllocationStatus::Confirmed,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(2),
            'quantity' => 1,
        ]);
        $candidate = Reservation::factory()->create(['property_id' => $property->id, 'status' => ReservationStatus::Draft]);
        $candidate->allocations()->create([
            'resource_id' => $resource->id,
            'status' => AllocationStatus::Tentative,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(2),
            'quantity' => 1,
        ]);

        $this->expectException(AllocationConflictException::class);
        app(ReservationService::class)->transition($candidate, ReservationStatus::Hold);
    }
}
