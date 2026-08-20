<?php

namespace Tests\Feature;

use App\Enums\AllocationStatus;
use App\Enums\ReservationStatus;
use App\Models\Allocation;
use App\Models\Membership;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\Tenant;
use App\Services\AllocationWorkflowService;
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
