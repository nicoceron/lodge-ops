<?php

namespace Tests\Feature;

use App\Enums\AllocationStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\InvalidStatusTransitionException;
use App\Models\Reservation;
use App\Models\Resource;
use App\Services\ReservationService;
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
}
