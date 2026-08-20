<?php

namespace Tests\Feature;

use App\Models\CommercialPromotion;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\Resource;
use App\Services\BookingQuoteService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class CommercialPricingBenchmarkTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_representative_seasonal_group_and_buyout_searches_are_bounded(): void
    {
        [, $property] = $this->tenantEnvironment();
        $category = $this->category($property, 'room');
        Resource::factory()->count(20)->create(['property_id' => $property->id, 'category_id' => $category->id, 'capacity' => 12]);
        $arrival = CarbonImmutable::now()->addDays(90)->startOfDay()->addHours(15);
        $scenarios = [];

        foreach ([
            'seasonal' => ['amount_minor' => 25_000, 'starts_on' => $arrival->toDateString(), 'ends_on' => $arrival->addMonth()->toDateString()],
            'group' => ['amount_minor' => 30_000, 'group_tiers' => [['minimum_guests' => 4, 'adjustment_basis_points' => -1000]]],
            'buyout' => ['amount_minor' => 200_000, 'buyout_only' => true],
        ] as $name => $ruleData) {
            $plan = RatePlan::query()->create(['property_id' => $property->id, 'name' => ucfirst($name), 'currency' => 'USD', 'maximum_occupancy' => 12]);
            RateRule::query()->create($ruleData + ['rate_plan_id' => $plan->id, 'resource_category_id' => $category->id]);
            DB::table('rate_plans')->where('id', $plan->id)->update(['state' => 'published', 'published_at' => now()]);
            $scenarios[$name] = [
                'property_id' => $property->id, 'rate_plan_id' => $plan->id, 'resource_category_id' => $category->id,
                'starts_at' => $arrival, 'ends_at' => $arrival->addDays(7), 'adults' => $name === 'group' ? 6 : 2,
                'children' => 0, 'is_buyout' => $name === 'buyout',
            ];
        }
        foreach (range(1, 5) as $index) {
            CommercialPromotion::query()->create([
                'property_id' => $property->id, 'name' => "Auto {$index}", 'public_label' => "Auto {$index}",
                'state' => 'published', 'currency' => 'USD', 'discount_type' => 'percentage',
                'percentage_basis_points' => 100, 'priority' => $index, 'published_at' => now(), 'approval_owner_id' => auth()->id(),
            ]);
        }

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });
        $result = [];
        foreach ($scenarios as $name => $input) {
            app(BookingQuoteService::class)->preview($input);
            $queryCount = 0;
            $durations = [];
            $queries = [];
            foreach (range(1, 20) as $_) {
                $before = $queryCount;
                $started = hrtime(true);
                app(BookingQuoteService::class)->preview($input);
                $durations[] = (hrtime(true) - $started) / 1_000_000;
                $queries[] = $queryCount - $before;
            }
            sort($durations);
            $p95 = $durations[(int) ceil(count($durations) * 0.95) - 1];
            $result[$name] = ['queries_max' => max($queries), 'p95_ms' => round($p95, 2)];
            $this->assertLessThanOrEqual(16, max($queries), "{$name} query count indicates an N+1 regression");
            $this->assertLessThan(250, $p95, "{$name} p95 exceeded the local acceptance budget");
        }

        fwrite(STDOUT, "\nCOMMERCIAL_BENCHMARK ".json_encode($result, JSON_THROW_ON_ERROR)."\n");
    }
}
