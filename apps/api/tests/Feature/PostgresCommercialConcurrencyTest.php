<?php

namespace Tests\Feature;

use App\Models\CommercialPromotion;
use App\Models\Guest;
use App\Models\Membership;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\Tenant;
use App\Models\VoucherRedemption;
use App\Services\BookingQuoteService;
use App\Services\CommercialPromotionService;
use App\Services\CommitBookingQuote;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;
use Throwable;

class PostgresCommercialConcurrencyTest extends TestCase
{
    use CreatesTenant, DatabaseMigrations;

    public function test_final_room_and_final_voucher_use_have_one_winner_and_rollback_together(): void
    {
        $this->requirePostgresConcurrency();
        [$tenant, $property, , $membership] = $this->tenantEnvironment();
        $category = $this->category($property, 'room');
        $room = Resource::factory()->create(['property_id' => $property->id, 'category_id' => $category->id, 'capacity' => 2]);
        $plan = RatePlan::query()->create(['property_id' => $property->id, 'name' => 'Race rate', 'currency' => 'USD']);
        RateRule::query()->create(['rate_plan_id' => $plan->id, 'resource_category_id' => $category->id, 'amount_minor' => 10_000]);
        $promotion = CommercialPromotion::query()->create([
            'property_id' => $property->id, 'name' => 'Last use', 'public_label' => 'Last use', 'state' => 'published',
            'currency' => 'USD', 'discount_type' => 'fixed', 'fixed_amount_minor' => 1000,
            'requires_code' => true, 'usage_limit' => 1, 'published_at' => now(), 'approval_owner_id' => auth()->id(),
        ]);
        app(CommercialPromotionService::class)->issueVoucher($promotion, 'RACE-ONLY', ['usage_limit' => 1]);
        $input = [
            'property_id' => $property->id, 'rate_plan_id' => $plan->id, 'resource_category_id' => $category->id,
            'resource_id' => $room->id, 'starts_at' => now()->addDays(40), 'ends_at' => now()->addDays(42),
            'adults' => 1, 'children' => 0, 'voucher_code' => 'race-only',
        ];
        $first = app(BookingQuoteService::class)->create($input);
        $second = app(BookingQuoteService::class)->create($input);
        $firstGuest = Guest::factory()->create();
        $secondGuest = Guest::factory()->create();

        $results = $this->concurrently([
            fn (): string => app(CommitBookingQuote::class)->handle($first, $firstGuest->id)->id,
            fn (): string => app(CommitBookingQuote::class)->handle($second, $secondGuest->id)->id,
        ], $tenant, $membership);
        app(TenantContext::class)->set($tenant, $membership);

        $this->assertSame(1, collect($results)->where('ok', true)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(1, collect($results)->where('ok', false)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(1, Reservation::query()->count());
        $this->assertSame(1, VoucherRedemption::query()->count());
        $this->assertSame(1, DB::table('allocations')->where('resource_id', $room->id)->where('status', 'tentative')->count());
        $this->assertSame(19_000, (int) DB::table('folio_lines')->sum('gross_amount_minor'));
    }

    /** @param array<int, callable(): string> $operations @return array<int, array{ok: bool, result?: string, error?: string}> */
    private function concurrently(array $operations, Tenant $tenant, Membership $membership): array
    {
        $directory = sys_get_temp_dir().'/inn-commercial-race-'.Str::random(12);
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
