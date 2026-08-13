<?php

namespace Tests\Feature;

use App\Enums\AllocationStatus;
use App\Enums\MembershipRole;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Enums\TaskStatus;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
            'category_id' => $this->category($property, 'room')->id,
            'capacity' => 1,
            'is_active' => true,
        ]);
        Resource::factory()->create([
            'property_id' => $otherProperty->id,
            'category_id' => $this->category($otherProperty, 'room')->id,
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
        $this->assertSame(1, $projection['active_stay_places']);
        $this->assertSame(['Own property task'], collect($projection['tasks'])->pluck('title')->all());
        $this->assertSame('attention', $projection['arrival_parties'][0]['readiness']);
    }

    public function test_sales_dashboard_projection_does_not_expose_operational_tasks(): void
    {
        [$tenant, $property, $user] = $this->tenantEnvironment(MembershipRole::Sales);
        OperationalTask::query()->create([
            'property_id' => $property->id,
            'assignee_id' => $user->id,
            'title' => 'Private operations handoff',
            'status' => TaskStatus::Todo,
            'priority' => 'urgent',
            'due_at' => now()->addHour(),
        ]);

        $projection = app(DashboardProjectionService::class)->build();

        $this->assertSame(0, $projection['open_tasks']);
        $this->assertSame(0, $projection['overdue_tasks']);
        $this->assertSame([], $projection['tasks']);
        $this->assertSame(array_fill(0, 14, 0), $projection['trend']['work_due']);
    }

    public function test_dashboard_counts_completed_departures_and_has_no_readiness_percentage_without_upcoming_stays(): void
    {
        CarbonImmutable::setTestNow('2026-08-11 15:00:00 UTC');
        [$tenant, $property] = $this->tenantEnvironment(MembershipRole::Operations);
        $tenant->update(['timezone' => 'UTC']);
        Reservation::factory()->create([
            'property_id' => $property->id,
            'status' => ReservationStatus::CheckedOut,
            'starts_at' => '2026-08-08 15:00:00',
            'ends_at' => '2026-08-11 11:00:00',
        ]);

        $projection = app(DashboardProjectionService::class)->build();

        $this->assertSame(1, $projection['departures']);
        $this->assertSame(0, $projection['readiness']['total']);
        $this->assertNull($projection['readiness']['percent']);
    }

    public function test_dashboard_projection_reuses_a_short_lived_role_scoped_snapshot(): void
    {
        $this->tenantEnvironment(MembershipRole::Operations);
        Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $first = app(DashboardProjectionService::class)->build();
        $queriesAfterFirstBuild = count(DB::getQueryLog());
        $second = app(DashboardProjectionService::class)->build();

        $this->assertEquals($first, $second);
        $this->assertSame($queriesAfterFirstBuild, count(DB::getQueryLog()));
    }

    public function test_arrival_attention_uses_every_readiness_check(): void
    {
        CarbonImmutable::setTestNow('2026-08-11 15:00:00 UTC');
        [$tenant, $property] = $this->tenantEnvironment(MembershipRole::Operations);
        $tenant->update(['timezone' => 'UTC']);
        $guest = Guest::factory()->create(['preferences' => null]);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'primary_guest_id' => $guest->id,
            'status' => ReservationStatus::Confirmed,
            'total_minor' => 0,
            'starts_at' => '2026-08-12 15:00:00',
            'ends_at' => '2026-08-14 11:00:00',
        ]);
        $room = Resource::factory()->create([
            'property_id' => $property->id,
            'category_id' => $this->category($property, 'room')->id,
            'is_active' => true,
        ]);
        Allocation::query()->create([
            'reservation_id' => $reservation->id,
            'resource_id' => $room->id,
            'starts_at' => $reservation->starts_at,
            'ends_at' => $reservation->ends_at,
            'quantity' => 1,
            'status' => AllocationStatus::Confirmed,
        ]);

        $projection = app(DashboardProjectionService::class)->build();

        $this->assertSame(1, $projection['needs_attention']);
        $this->assertSame(['Kitchen brief'], $projection['attention_stays'][0]['reasons']);
        $this->assertSame(75.0, (float) $projection['readiness']['percent']);
    }

    public function test_dashboard_builds_a_bounded_property_scoped_operational_trend(): void
    {
        CarbonImmutable::setTestNow('2026-08-11 15:00:00 UTC');
        [$tenant, $property] = $this->tenantEnvironment(MembershipRole::Operations);
        $tenant->update(['timezone' => 'UTC']);
        $otherProperty = Property::factory()->for($tenant)->create();
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $rooms = collect(range(1, 2))->map(fn () => Resource::factory()->create([
            'property_id' => $property->id,
            'category_id' => $this->category($property, 'room')->id,
            'is_active' => true,
        ]));
        Resource::factory()->create([
            'property_id' => $otherProperty->id,
            'category_id' => $this->category($otherProperty, 'room')->id,
            'is_active' => true,
        ]);

        $reservations = [];
        foreach ([0, 2] as $index => $daysFromToday) {
            $startsAt = $today->addDays($daysFromToday)->addHours(15);
            $reservations[] = $reservation = Reservation::factory()->create([
                'property_id' => $property->id,
                'status' => ReservationStatus::Confirmed,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->addDays(2)->subHours(4),
            ]);
            Allocation::query()->create([
                'reservation_id' => $reservation->id,
                'resource_id' => $rooms[$index]->id,
                'starts_at' => $reservation->starts_at,
                'ends_at' => $reservation->ends_at,
                'quantity' => 1,
                'status' => AllocationStatus::Confirmed,
            ]);
        }
        $inactiveRoom = Resource::factory()->create([
            'property_id' => $property->id,
            'category_id' => $this->category($property, 'room')->id,
            'is_active' => false,
        ]);
        Allocation::query()->create([
            'reservation_id' => $reservations[0]->id,
            'resource_id' => $inactiveRoom->id,
            'starts_at' => $reservations[0]->starts_at,
            'ends_at' => $reservations[0]->ends_at,
            'quantity' => 1,
            'status' => AllocationStatus::Confirmed,
        ]);
        Reservation::factory()->create([
            'property_id' => $otherProperty->id,
            'status' => ReservationStatus::Confirmed,
            'starts_at' => $today->addHours(15),
            'ends_at' => $today->addDays(2)->addHours(11),
        ]);
        foreach ([
            ['property_id' => $property->id, 'title' => 'Overdue own task', 'due_at' => $today->subDay()],
            ['property_id' => $property->id, 'title' => 'Upcoming own task', 'due_at' => $today->addDay()],
            ['property_id' => $otherProperty->id, 'title' => 'Other property task', 'due_at' => $today->addDay()],
        ] as $task) {
            OperationalTask::query()->create($task + [
                'status' => 'todo',
                'priority' => 'normal',
            ]);
        }

        $projection = app(DashboardProjectionService::class)->build();
        $trend = $projection['trend'];

        $this->assertCount(14, $trend['labels']);
        $this->assertSame('Aug 5', $trend['labels'][0]);
        $this->assertSame('Aug 18', $trend['labels'][13]);
        $this->assertSame(1, $trend['arrivals'][6]);
        $this->assertSame(1, $trend['arrivals'][8]);
        $this->assertSame(1, $trend['departures'][8]);
        $this->assertSame(50.0, $trend['occupancy_percent'][6]);
        $this->assertSame(100.0, $trend['occupancy_percent'][8]);
        $this->assertCount(8, $trend['attention']);
        $this->assertSame(1, $trend['attention'][0]);
        $this->assertSame(1, $trend['attention'][2]);
        $this->assertSame(1, $trend['work_due'][5]);
        $this->assertSame(1, $trend['work_due'][7]);
        $this->assertSame(2, $projection['open_tasks']);
        $this->assertSame(1, $projection['overdue_tasks']);
        $this->assertSame(1, $projection['occupied_stay_places']);
        $this->assertSame(50.0, $projection['occupancy_percent']);
        $this->assertCount(2, $projection['attention_stays']);
        $this->assertSame(['Guest details', 'Payment balance', 'Kitchen brief'], $projection['attention_stays'][0]['reasons']);
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
