<?php

namespace Tests\Feature;

use App\Enums\AllocationStatus;
use App\Enums\MembershipRole;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Enums\ResourceType;
use App\Models\Allocation;
use App\Models\Guest;
use App\Models\OperationalTask;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Resource;
use App\Services\Projections\DashboardProjectionService;
use App\Services\Projections\OperationsProjectionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class ProjectionPropertyIsolationTest extends TestCase
{
    use CreatesTenant;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_property_scoped_dashboard_excludes_other_property_data_and_other_currency_payments(): void
    {
        CarbonImmutable::setTestNow('2026-08-11 15:00:00 UTC');
        [$tenant, $property] = $this->tenantEnvironment(MembershipRole::Operations);
        $otherProperty = Property::factory()->for($tenant)->create();
        $guest = Guest::factory()->create();
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'primary_guest_id' => $guest->id,
            'status' => ReservationStatus::Confirmed,
            'currency' => 'USD',
            'total_minor' => 50_000,
            'starts_at' => now(),
            'ends_at' => now()->addDays(2),
        ]);
        Reservation::factory()->create([
            'property_id' => $otherProperty->id,
            'status' => ReservationStatus::Confirmed,
            'starts_at' => now(),
            'ends_at' => now()->addDays(2),
        ]);
        $room = Resource::factory()->create([
            'property_id' => $property->id,
            'type' => ResourceType::Room,
            'capacity' => 1,
            'is_active' => true,
        ]);
        Resource::factory()->create([
            'property_id' => $otherProperty->id,
            'type' => ResourceType::Room,
            'capacity' => 1,
            'is_active' => true,
        ]);
        Allocation::query()->create([
            'reservation_id' => $reservation->id,
            'resource_id' => $room->id,
            'starts_at' => now(),
            'ends_at' => now()->addDays(2),
            'quantity' => 1,
            'status' => AllocationStatus::Confirmed,
        ]);
        Payment::query()->create([
            'reservation_id' => $reservation->id,
            'provider' => 'manual',
            'status' => PaymentStatus::Succeeded,
            'method' => 'cash',
            'currency' => 'ARS',
            'amount_minor' => 50_000,
            'processed_at' => now(),
        ]);
        OperationalTask::query()->create([
            'property_id' => $property->id,
            'title' => 'Own property task',
            'status' => 'todo',
            'priority' => 'high',
        ]);
        OperationalTask::query()->create([
            'property_id' => $otherProperty->id,
            'title' => 'Other property task',
            'status' => 'todo',
            'priority' => 'urgent',
        ]);

        $projection = app(DashboardProjectionService::class)->build();

        $this->assertSame(1, $projection['arrivals']);
        $this->assertSame(1, $projection['active_rooms']);
        $this->assertSame(['Own property task'], collect($projection['tasks'])->pluck('title')->all());
        $this->assertSame('attention', $projection['arrival_parties'][0]['readiness']);
    }

    public function test_property_scoped_kitchen_projection_excludes_other_property_dietary_data(): void
    {
        CarbonImmutable::setTestNow('2026-08-11 15:00:00 UTC');
        [$tenant, $property, $user] = $this->tenantEnvironment(MembershipRole::Kitchen);
        $otherProperty = Property::factory()->for($tenant)->create();
        $ownGuest = Guest::factory()->create(['preferences' => ['allergies' => ['Peanuts']]]);
        $otherGuest = Guest::factory()->create(['preferences' => ['allergies' => ['Shellfish']]]);
        $ownReservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'primary_guest_id' => $ownGuest->id,
            'status' => ReservationStatus::CheckedIn,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);
        Reservation::factory()->create([
            'property_id' => $otherProperty->id,
            'primary_guest_id' => $otherGuest->id,
            'status' => ReservationStatus::CheckedIn,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);

        $projection = app(OperationsProjectionService::class)->build($user);
        $restrictions = collect($projection['kitchen']['restrictions'])->pluck('label')->all();

        $this->assertContains('Peanuts', $restrictions);
        $this->assertNotContains('Shellfish', $restrictions);
        $this->assertSame($ownReservation->adults + $ownReservation->children, $projection['kitchen']['guest_count']);
    }
}
