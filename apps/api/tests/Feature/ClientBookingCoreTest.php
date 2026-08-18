<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\CancellationPolicy;
use App\Models\CancellationPolicyTier;
use App\Models\DepositPolicy;
use App\Models\Guest;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\Resource;
use App\Models\TaxRule;
use App\Services\BookingQuoteService;
use App\Services\CommitBookingQuote;
use App\Services\FolioService;
use App\Services\ReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class ClientBookingCoreTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_server_quote_commits_an_atomic_priced_hold_and_configured_deposits(): void
    {
        [, $property] = $this->tenantEnvironment();
        $category = $this->category($property, 'room');
        $room = Resource::factory()->create([
            'property_id' => $property->id,
            'category_id' => $category->id,
            'capacity' => 3,
        ]);
        $depositPolicy = DepositPolicy::query()->create([
            'property_id' => $property->id,
            'name' => 'Forty percent',
            'requirement_type' => 'percentage',
            'percentage_basis_points' => 4000,
            'balance_due_offset_days' => 21,
            'is_default' => true,
        ]);
        $cancellation = CancellationPolicy::query()->create([
            'property_id' => $property->id,
            'name' => 'Flexible lodge',
            'summary' => 'Ten percent retained inside fourteen days.',
            'is_default' => true,
        ]);
        CancellationPolicyTier::query()->create([
            'cancellation_policy_id' => $cancellation->id,
            'days_before_arrival' => 14,
            'retained_basis_points' => 1000,
        ]);
        $plan = RatePlan::query()->create([
            'property_id' => $property->id,
            'deposit_policy_id' => $depositPolicy->id,
            'cancellation_policy_id' => $cancellation->id,
            'name' => 'Lodge flexible',
            'currency' => 'USD',
            'maximum_occupancy' => 3,
        ]);
        RateRule::query()->create([
            'rate_plan_id' => $plan->id,
            'resource_category_id' => $category->id,
            'amount_minor' => 10_000,
            'price_type' => 'per_night',
        ]);
        TaxRule::query()->create([
            'property_id' => $property->id,
            'name' => 'VAT',
            'calculation_type' => 'percentage',
            'percentage_basis_points' => 1000,
        ]);
        $guest = Guest::factory()->create();
        $starts = now()->addMonth()->startOfDay()->addHours(15);
        $ends = $starts->copy()->addDays(2)->subHours(4);

        $quote = app(BookingQuoteService::class)->create([
            'property_id' => $property->id,
            'rate_plan_id' => $plan->id,
            'resource_category_id' => $category->id,
            'resource_id' => $room->id,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'adults' => 2,
            'children' => 1,
        ]);

        $this->assertSame(20_000, $quote->subtotal_minor);
        $this->assertSame(2_000, $quote->tax_minor);
        $this->assertSame(22_000, $quote->total_minor);
        $this->assertCount(3, $quote->lines);
        $this->assertSame(4000, $quote->deposit_policy_snapshot['percentage_basis_points']);

        $plan->rules()->firstOrFail()->update(['amount_minor' => 99_000]);
        $reservation = app(CommitBookingQuote::class)->handle($quote, $guest->id, source: 'direct');

        $this->assertSame(ReservationStatus::Hold, $reservation->status);
        $this->assertSame(22_000, $reservation->total_minor);
        $this->assertSame($room->id, $reservation->allocations->first()->resource_id);
        $this->assertSame($quote->checksum, $reservation->price_snapshot['checksum']);
        $this->assertSame(22_000, app(FolioService::class)->summary($reservation)['balance_minor']);

        $confirmed = app(ReservationService::class)->confirm($reservation);
        $this->assertSame(ReservationStatus::Confirmed, $confirmed->status);
        $this->assertDatabaseHas('deposits', [
            'reservation_id' => $reservation->id,
            'schedule_type' => 'deposit',
            'amount_minor' => 8_800,
        ]);
        $this->assertDatabaseHas('deposits', [
            'reservation_id' => $reservation->id,
            'schedule_type' => 'balance',
            'amount_minor' => 13_200,
        ]);
    }

    public function test_exact_accommodation_is_exclusive_even_when_its_guest_capacity_is_not_full(): void
    {
        [, $property] = $this->tenantEnvironment();
        $category = $this->category($property, 'room');
        $room = Resource::factory()->create([
            'property_id' => $property->id,
            'category_id' => $category->id,
            'capacity' => 4,
        ]);
        $plan = RatePlan::query()->create([
            'property_id' => $property->id,
            'name' => 'Base',
            'currency' => 'USD',
            'maximum_occupancy' => 4,
        ]);
        RateRule::query()->create([
            'rate_plan_id' => $plan->id,
            'resource_category_id' => $category->id,
            'amount_minor' => 10_000,
        ]);
        $input = [
            'property_id' => $property->id,
            'rate_plan_id' => $plan->id,
            'resource_category_id' => $category->id,
            'resource_id' => $room->id,
            'starts_at' => now()->addMonths(2),
            'ends_at' => now()->addMonths(2)->addDays(2),
            'adults' => 1,
            'children' => 0,
        ];
        $first = app(BookingQuoteService::class)->create($input);
        app(CommitBookingQuote::class)->handle($first, Guest::factory()->create()->id);

        $this->expectException(ValidationException::class);
        app(BookingQuoteService::class)->create($input);
    }

    public function test_quote_financial_snapshot_cannot_be_mutated(): void
    {
        [, $property] = $this->tenantEnvironment();
        $category = $this->category($property, 'room');
        Resource::factory()->create(['property_id' => $property->id, 'category_id' => $category->id]);
        $plan = RatePlan::query()->create(['property_id' => $property->id, 'name' => 'Base', 'currency' => 'USD']);
        RateRule::query()->create(['rate_plan_id' => $plan->id, 'resource_category_id' => $category->id, 'amount_minor' => 1000]);
        $quote = app(BookingQuoteService::class)->create([
            'property_id' => $property->id,
            'rate_plan_id' => $plan->id,
            'resource_category_id' => $category->id,
            'starts_at' => now()->addMonth(),
            'ends_at' => now()->addMonth()->addDay(),
            'adults' => 1,
        ]);

        $this->expectException(LogicException::class);
        $quote->update(['total_minor' => 1]);
    }
}
