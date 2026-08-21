<?php

namespace Tests\Feature;

use App\Enums\AllocationStatus;
use App\Enums\ReservationStatus;
use App\Models\Allocation;
use App\Models\Guest;
use App\Models\Membership;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\Tenant;
use App\Services\AllocationWorkflowService;
use App\Services\AmendReservation;
use App\Services\BookingQuoteService;
use App\Services\CommitBookingQuote;
use App\Services\ReallocateResource;
use App\Services\ReservationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;
use Throwable;

class PostgresOperationalAcceptanceConcurrencyTest extends TestCase
{
    use CreatesTenant, DatabaseMigrations;

    protected function tearDown(): void
    {
        putenv('COMMERCIAL_TEST_TEARDOWN=1');
        try {
            parent::tearDown();
        } finally {
            putenv('COMMERCIAL_TEST_TEARDOWN');
        }
    }

    public function test_exact_resource_capacity_race_has_one_winner(): void
    {
        $this->requirePostgresConcurrency();
        [$tenant, $property, , $membership] = $this->tenantEnvironment();
        $category = $this->category($property, 'boat');
        $boat = Resource::factory()->create([
            'property_id' => $property->id,
            'category_id' => $category->id,
            'capacity' => 1,
        ]);
        $startsAt = now()->addDays(30)->startOfHour();
        $endsAt = $startsAt->clone()->addHours(4);
        $reservations = collect([1, 2])->map(fn (): Reservation => Reservation::factory()->create([
            'property_id' => $property->id,
            'status' => ReservationStatus::Confirmed,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]));

        $results = $this->concurrently($reservations->map(fn (Reservation $reservation): callable => fn (): string => app(AllocationWorkflowService::class)->create($reservation, [
            'resource_id' => $boat->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'quantity' => 1,
        ])->id)->all(), $tenant, $membership);
        app(TenantContext::class)->set($tenant, $membership);

        $this->assertOneWinner($results);
        $this->assertSame(1, Allocation::query()
            ->where('resource_id', $boat->id)
            ->where('status', AllocationStatus::Confirmed)
            ->count());
    }

    public function test_unassigned_category_capacity_race_has_one_winner(): void
    {
        $this->requirePostgresConcurrency();
        [$tenant, $property, , $membership] = $this->tenantEnvironment();
        $category = $this->category($property, 'vehicle');
        Resource::factory()->create([
            'property_id' => $property->id,
            'category_id' => $category->id,
            'capacity' => 1,
        ]);
        $startsAt = now()->addDays(35)->startOfHour();
        $endsAt = $startsAt->clone()->addHours(8);
        $reservations = collect([1, 2])->map(fn (): Reservation => Reservation::factory()->create([
            'property_id' => $property->id,
            'status' => ReservationStatus::Confirmed,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]));

        $results = $this->concurrently($reservations->map(fn (Reservation $reservation): callable => fn (): string => app(AllocationWorkflowService::class)->create($reservation, [
            'requested_category_id' => $category->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'quantity' => 1,
        ])->id)->all(), $tenant, $membership);
        app(TenantContext::class)->set($tenant, $membership);

        $this->assertOneWinner($results);
        $this->assertSame(1, Allocation::query()
            ->where('requested_category_id', $category->id)
            ->whereNull('resource_id')
            ->where('status', AllocationStatus::Confirmed)
            ->count());
    }

    public function test_swap_race_rolls_back_both_assignments_when_competing_capacity_wins(): void
    {
        $this->requirePostgresConcurrency();
        [$tenant, $property, $manager, $membership] = $this->tenantEnvironment();
        $category = $this->category($property, 'guide');
        [$guideA, $guideB] = Resource::factory()->guide()->count(2)->create([
            'property_id' => $property->id, 'category_id' => $category->id,
        ])->all();
        $starts = now()->addDays(40)->startOfHour();
        $ends = $starts->clone()->addHours(5);
        $primary = Reservation::factory()->create(['property_id' => $property->id, 'status' => ReservationStatus::Confirmed, 'starts_at' => $starts, 'ends_at' => $ends]);
        $other = Reservation::factory()->create(['property_id' => $property->id, 'status' => ReservationStatus::Confirmed, 'starts_at' => $starts, 'ends_at' => $ends]);
        $contender = Reservation::factory()->create(['property_id' => $property->id, 'status' => ReservationStatus::Confirmed, 'starts_at' => $starts, 'ends_at' => $ends]);
        $primaryAllocation = Allocation::query()->create([
            'reservation_id' => $primary->id, 'resource_id' => $guideB->id, 'status' => AllocationStatus::Confirmed,
            'starts_at' => $starts, 'ends_at' => $ends, 'quantity' => 1,
        ]);
        $otherAllocation = Allocation::query()->create([
            'reservation_id' => $other->id, 'resource_id' => $guideA->id, 'status' => AllocationStatus::Confirmed,
            'starts_at' => $starts, 'ends_at' => $ends, 'quantity' => 1,
        ]);

        $results = $this->concurrently([
            fn (): string => app(ReallocateResource::class)->handle(
                $primary, $primaryAllocation, $guideA, $manager->id, $otherAllocation, 'PostgreSQL swap race.',
            )->id,
            fn (): string => app(AllocationWorkflowService::class)->create($contender, [
                'resource_id' => $guideB->id, 'starts_at' => $starts, 'ends_at' => $ends, 'quantity' => 1,
            ], $manager->id, 'PostgreSQL competing allocation.', true)->id,
        ], $tenant, $membership);
        app(TenantContext::class)->set($tenant, $membership);

        $this->assertOneWinner($results);
        $this->assertSame(1, $primary->allocations()->where('status', '!=', AllocationStatus::Released)->count());
        $this->assertSame(1, $other->allocations()->where('status', '!=', AllocationStatus::Released)->count());
        $this->assertContains($primary->changes()->where('type', 'resource_swapped')->count(), [0, 1]);
        $this->assertSame(
            $primary->changes()->where('type', 'resource_swapped')->count(),
            $other->changes()->where('type', 'resource_swapped')->count(),
            'A swap must audit both reservations or roll back both sides.',
        );
    }

    public function test_allocation_race_with_cancellation_never_leaves_capacity_on_terminal_reservation(): void
    {
        $this->requirePostgresConcurrency();
        [$tenant, $property, $manager, $membership] = $this->tenantEnvironment();
        $category = $this->category($property, 'vehicle');
        $vehicle = Resource::factory()->create(['property_id' => $property->id, 'category_id' => $category->id]);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id, 'status' => ReservationStatus::Confirmed,
            'starts_at' => now()->addDays(45), 'ends_at' => now()->addDays(46),
        ]);

        $results = $this->concurrently([
            fn (): string => app(AllocationWorkflowService::class)->create($reservation, [
                'resource_id' => $vehicle->id, 'starts_at' => $reservation->starts_at,
                'ends_at' => $reservation->ends_at, 'quantity' => 1,
            ], $manager->id, 'Allocation versus cancellation race.', true)->id,
            fn (): string => app(ReservationService::class)->transition(
                $reservation, ReservationStatus::Cancelled, metadata: ['reason' => 'Concurrent cancellation.'],
            )->id,
        ], $tenant, $membership);
        app(TenantContext::class)->set($tenant, $membership);

        $this->assertSame(1, collect($results)->filter(fn (array $result): bool => ($result['ok'] ?? false) && ($result['result'] ?? null) === $reservation->id)->count());
        $this->assertSame(ReservationStatus::Cancelled, $reservation->fresh()->status);
        $this->assertSame(0, $reservation->allocations()->where('status', '!=', AllocationStatus::Released)->count());
    }

    public function test_allocation_race_with_amendment_serializes_without_lost_assignment_or_revision(): void
    {
        $this->requirePostgresConcurrency();
        [$tenant, $property, $manager, $membership] = $this->tenantEnvironment();
        $roomCategory = $this->category($property, 'room');
        $guideCategory = $this->category($property, 'guide');
        $room = Resource::factory()->create(['property_id' => $property->id, 'category_id' => $roomCategory->id, 'capacity' => 4]);
        $guide = Resource::factory()->guide()->create(['property_id' => $property->id, 'category_id' => $guideCategory->id]);
        $plan = RatePlan::query()->create(['property_id' => $property->id, 'name' => 'Race amendment plan', 'currency' => 'USD', 'maximum_occupancy' => 4]);
        RateRule::query()->create(['rate_plan_id' => $plan->id, 'resource_category_id' => $roomCategory->id, 'amount_minor' => 20_000]);
        $plan->forceFill(['state' => 'published', 'published_at' => now()])->save();
        $input = [
            'property_id' => $property->id, 'rate_plan_id' => $plan->id, 'resource_category_id' => $roomCategory->id,
            'resource_id' => $room->id, 'starts_at' => now()->addDays(50)->startOfDay(),
            'ends_at' => now()->addDays(53)->startOfDay(), 'adults' => 2, 'children' => 0,
        ];
        $quote = app(BookingQuoteService::class)->create($input);
        $reservation = app(CommitBookingQuote::class)->handle($quote, Guest::factory()->create()->id);
        $reservation = app(ReservationService::class)->confirm($reservation);
        $initialRevision = $reservation->revision;

        $results = $this->concurrently([
            fn (): string => app(AllocationWorkflowService::class)->create($reservation, [
                'resource_id' => $guide->id, 'starts_at' => $reservation->starts_at->addHour(),
                'ends_at' => $reservation->ends_at->subHour(), 'quantity' => 1,
            ], $manager->id, 'Allocation versus amendment race.', true)->id,
            fn (): string => app(AmendReservation::class)->handle($reservation, $input, $manager->id)->id,
        ], $tenant, $membership);
        app(TenantContext::class)->set($tenant, $membership);

        $this->assertSame(2, collect($results)->where('ok', true)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $fresh = $reservation->fresh();
        $this->assertGreaterThanOrEqual($initialRevision + 2, $fresh->revision);
        $this->assertSame(1, $fresh->allocations()->where('status', '!=', AllocationStatus::Released)->where('resource_id', $room->id)->count());
        $this->assertSame(1, $fresh->allocations()->where('status', '!=', AllocationStatus::Released)->where('resource_id', $guide->id)->count());
        $this->assertDatabaseHas('reservation_changes', ['reservation_id' => $fresh->id, 'type' => 'resource_assigned']);
        $this->assertDatabaseHas('reservation_changes', ['reservation_id' => $fresh->id, 'type' => 'amendment']);
    }

    /** @param array<int, array{ok: bool, result?: string, error?: string}> $results */
    private function assertOneWinner(array $results): void
    {
        $this->assertSame(1, collect($results)->where('ok', true)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(1, collect($results)->where('ok', false)->count(), json_encode($results, JSON_THROW_ON_ERROR));
    }

    /** @param array<int, callable(): string> $operations @return array<int, array{ok: bool, result?: string, error?: string}> */
    private function concurrently(array $operations, Tenant $tenant, Membership $membership): array
    {
        $directory = sys_get_temp_dir().'/inn-operational-race-'.Str::random(12);
        mkdir($directory, 0700, true);
        $barrier = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($barrier === false) {
            $this->fail('Unable to create the concurrency barrier.');
        }

        $children = [];
        foreach ($operations as $index => $operation) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('Unable to fork a PostgreSQL concurrency worker.');
            }
            if ($pid === 0) {
                fclose($barrier[0]);
                fread($barrier[1], 1);
                try {
                    DB::purge();
                    DB::reconnect();
                    app(TenantContext::class)->set($tenant, $membership);
                    $payload = ['ok' => true, 'result' => $operation()];
                } catch (Throwable $exception) {
                    $payload = ['ok' => false, 'error' => $exception::class.': '.$exception->getMessage()];
                }
                file_put_contents("{$directory}/{$index}.json", json_encode($payload, JSON_THROW_ON_ERROR));
                exit(0);
            }
            $children[] = $pid;
        }

        fclose($barrier[1]);
        fwrite($barrier[0], str_repeat('1', count($operations)));
        fclose($barrier[0]);
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $childStatus);
            $this->assertTrue(pcntl_wifexited($childStatus) && pcntl_wexitstatus($childStatus) === 0, "Concurrency worker {$pid} failed.");
        }
        DB::purge();
        DB::reconnect();

        $results = [];
        foreach (array_keys($operations) as $index) {
            $results[] = json_decode((string) file_get_contents("{$directory}/{$index}.json"), true, flags: JSON_THROW_ON_ERROR);
            unlink("{$directory}/{$index}.json");
        }
        rmdir($directory);

        return $results;
    }

    private function requirePostgresConcurrency(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL row-lock concurrency is exercised by the PostgreSQL gate.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('The PostgreSQL concurrency gate requires pcntl.');
        }
    }
}
