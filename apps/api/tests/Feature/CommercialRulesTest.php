<?php

namespace Tests\Feature;

use App\Contracts\Fiscal\FiscalSourceSnapshotFactory;
use App\Enums\ReservationStatus;
use App\Models\CatalogItem;
use App\Models\CommercialPromotion;
use App\Models\Guest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RatePlanService;
use App\Models\RateRule;
use App\Models\Resource;
use App\Models\TaxRule;
use App\Models\VoucherRedemptionEvent;
use App\Services\AmendReservation;
use App\Services\BookingQuoteService;
use App\Services\CommercialPromotionService;
use App\Services\CommitBookingQuote;
use App\Services\Documents\CanonicalJson;
use App\Services\FolioService;
use App\Services\QuoteExplanationService;
use App\Services\ReservationService;
use App\Services\VoucherCodeCanonicalizer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class CommercialRulesTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_advance_stay_arrival_blackout_and_property_business_date_restrictions_are_deterministic(): void
    {
        CarbonImmutable::setTestNow('2026-08-20 16:00:00+00:00');
        [, $property] = $this->tenantEnvironment();
        $property->update(['timezone' => 'America/Bogota']);
        [$plan, $rule, $category] = $this->baseRate($property->id, 10_000);
        $rule->update([
            'minimum_advance_days' => 10, 'maximum_advance_days' => 30,
            'minimum_stay' => 2, 'maximum_stay' => 3, 'allowed_arrival_days' => [7],
        ]);

        $valid = $this->quoteInput($property->id, $plan->id, $category->id, '2026-08-30 15:00:00-05:00', '2026-09-01 11:00:00-05:00');
        $quote = app(BookingQuoteService::class)->create($valid);
        $this->assertSame('2026-08-30', data_get($quote->calculation_snapshot, 'business_dates.arrival'));
        $this->assertSame('America/Bogota', data_get($quote->calculation_snapshot, 'business_dates.timezone'));

        foreach ([
            [array_merge($valid, ['starts_at' => '2026-08-29 15:00:00-05:00', 'ends_at' => '2026-08-31 11:00:00-05:00']), 'minimum advance / allowed arrival'],
            [array_merge($valid, ['ends_at' => '2026-09-03 11:00:00-05:00']), 'maximum stay'],
        ] as [$invalid, $label]) {
            try {
                app(BookingQuoteService::class)->create($invalid);
                $this->fail("Expected {$label} restriction failure.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('rate_plan_id', $exception->errors());
            }
        }

        $rule->update(['closed_to_arrival' => true]);
        try {
            app(BookingQuoteService::class)->create($valid);
            $this->fail('Expected closed-to-arrival restriction failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('rate_plan_id', $exception->errors());
        }
        $rule->update(['closed_to_arrival' => false, 'closed_to_departure' => true]);
        try {
            app(BookingQuoteService::class)->create($valid);
            $this->fail('Expected closed-to-departure restriction failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('rate_plan_id', $exception->errors());
        }

        $rule->update(['closed_to_departure' => false, 'buyout_only' => true]);
        try {
            app(BookingQuoteService::class)->create($valid);
            $this->fail('Expected buyout-only restriction failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('rate_plan_id', $exception->errors());
        }
        $this->assertSame(20_000, app(BookingQuoteService::class)->create($valid + ['is_buyout' => true])->total_minor);

        $rule->update(['blackout' => true]);
        $this->expectException(ValidationException::class);
        app(BookingQuoteService::class)->create($valid);
    }

    public function test_tax_discount_allocation_and_line_vs_total_rounding_are_versioned_inputs(): void
    {
        [, $property] = $this->tenantEnvironment();
        [$plan, , $category] = $this->baseRate($property->id, 5);
        $tax = TaxRule::query()->create([
            'property_id' => $property->id, 'name' => 'Rounding input', 'calculation_type' => 'percentage',
            'percentage_basis_points' => 1000, 'rounding_mode' => 'half_up', 'rounding_scope' => 'line',
            'taxable_discount_allocation' => 'before_tax',
        ]);
        $input = $this->quoteInput($property->id, $plan->id, $category->id, now()->addDays(20), now()->addDays(22));

        $this->assertSame(2, app(BookingQuoteService::class)->create($input)->tax_minor);
        $tax->update(['rounding_scope' => 'total']);
        $this->assertSame(1, app(BookingQuoteService::class)->create($input)->tax_minor);

        $this->promotion($property->id, 'Allocation input', false, 'fixed', null, 5, 10);
        $tax->update(['percentage_basis_points' => 5000, 'taxable_discount_allocation' => 'before_tax']);
        $beforeTax = app(BookingQuoteService::class)->create($input);
        $this->assertSame(3, $beforeTax->tax_minor);
        $this->assertSame('discounted_total', $beforeTax->lines->firstWhere('type', 'tax')->basis);

        $tax->update(['taxable_discount_allocation' => 'after_tax']);
        $afterTax = app(BookingQuoteService::class)->create($input);
        $this->assertSame(5, $afterTax->tax_minor);
        $this->assertSame('pre_discount_total', $afterTax->lines->firstWhere('type', 'tax')->basis);
    }

    public function test_guest_service_promotion_tax_and_rounding_order_is_frozen_and_explainable(): void
    {
        [, $property] = $this->tenantEnvironment();
        [$plan, $rule, $category] = $this->baseRate($property->id, 10_000);
        $rule->update(['adult_amount_minor' => 10_000, 'child_amount_minor' => 5_000, 'infant_amount_minor' => 0]);
        $included = CatalogItem::query()->create(['sku' => 'BREAKFAST', 'name' => 'Breakfast', 'type' => 'service', 'currency' => 'USD', 'price_minor' => 1500]);
        $transfer = CatalogItem::query()->create(['sku' => 'TRANSFER', 'name' => 'Transfer', 'type' => 'service', 'currency' => 'USD', 'price_minor' => 2000]);
        RatePlanService::query()->create(['rate_plan_id' => $plan->id, 'catalog_item_id' => $included->id, 'selection_type' => 'included', 'quantity_basis' => 'per_person', 'maximum_quantity' => 1]);
        RatePlanService::query()->create(['rate_plan_id' => $plan->id, 'catalog_item_id' => $transfer->id, 'selection_type' => 'optional', 'quantity_basis' => 'per_stay', 'maximum_quantity' => 2]);
        TaxRule::query()->create([
            'property_id' => $property->id, 'name' => 'Configured tax input', 'calculation_type' => 'percentage',
            'percentage_basis_points' => 1000, 'rounding_mode' => 'half_up', 'rounding_scope' => 'total',
            'taxable_discount_allocation' => 'before_tax', 'jurisdiction_inputs' => ['approval_status' => 'input_only'],
        ]);
        $automatic = $this->promotion($property->id, 'Advance offer', false, 'percentage', 1000, null, 20);
        $coded = $this->promotion($property->id, 'Welcome voucher', true, 'fixed', null, 5000, 10);
        $voucher = app(CommercialPromotionService::class)->issueVoucher($coded, 'Café-2026');

        $quote = app(BookingQuoteService::class)->create(array_merge($this->quoteInput(
            $property->id, $plan->id, $category->id, now()->addDays(20), now()->addDays(21),
        ), [
            'adults' => 2, 'children' => 1, 'infants' => 1,
            'optional_services' => [['id' => $transfer->id, 'quantity' => 1]], 'voucher_code' => "  cafe\u{0301}-2026  ",
        ]));

        $this->assertSame(7700, $quote->discount_minor);
        $this->assertSame(19_300, $quote->subtotal_minor);
        $this->assertSame(1930, $quote->tax_minor);
        $this->assertSame(21_230, $quote->total_minor);
        $this->assertSame($voucher->id, data_get($quote->calculation_snapshot, 'voucher_id'));
        $this->assertSame(['included_service', 'optional_service'], $quote->lines->whereIn('type', ['included_service', 'optional_service'])->pluck('type')->values()->all());
        $explanation = app(QuoteExplanationService::class)->project($quote);
        $this->assertTrue($explanation['historical_projection']);
        $this->assertSame(['eligibility_restrictions', 'base_occupancy_program', 'included_optional_services', 'promotions', 'fees_taxes', 'deposit'], data_get($explanation, 'calculation_snapshot.calculation_order'));
        $this->assertNotEmpty(collect($explanation['lines'])->pluck('rule_facts')->filter()->all());
        $this->assertSame($automatic->id, data_get($quote->calculation_snapshot, 'promotion_versions.0.promotion_id'));
    }

    public function test_voucher_code_canonicalization_is_unicode_case_stable_and_raw_code_is_not_retained(): void
    {
        [$tenant, $property] = $this->tenantEnvironment();
        $promotion = $this->promotion($property->id, 'Unicode', true, 'fixed', null, 1000, 1);
        $canonicalizer = app(VoucherCodeCanonicalizer::class);
        $this->assertSame($canonicalizer->hash($tenant->id, 'CAFÉ-2026'), $canonicalizer->hash($tenant->id, " cafe\u{0301}-2026 "));
        $voucher = app(CommercialPromotionService::class)->issueVoucher($promotion, " cafe\u{0301}-2026 ");
        $this->assertSame(64, strlen($voucher->code_hash));
        $this->assertStringNotContainsString('CAFÉ', json_encode($voucher->getAttributes(), JSON_THROW_ON_ERROR));

        try {
            app(VoucherCodeCanonicalizer::class)->canonicalize('x');
            $this->fail('Short codes must fail generically.');
        } catch (ValidationException $exception) {
            $this->assertSame('The voucher code is invalid.', $exception->errors()['voucher_code'][0]);
        }
    }

    public function test_voucher_session_limit_uses_only_a_tenant_keyed_session_hash(): void
    {
        [, $property] = $this->tenantEnvironment();
        [$plan, , $category] = $this->baseRate($property->id, 10_000);
        $promotion = $this->promotion($property->id, 'Session limit', true, 'fixed', null, 1000, 1);
        app(CommercialPromotionService::class)->issueVoucher($promotion, 'SESSION-ONLY', [
            'usage_limit' => 10, 'per_session_limit' => 1,
        ]);
        $sessionId = 'browser-session-opaque-123456';
        $first = app(BookingQuoteService::class)->create($this->quoteInput(
            $property->id, $plan->id, $category->id, now()->addDays(20), now()->addDays(21),
        ) + ['voucher_code' => 'session-only', 'promotion_session_id' => $sessionId]);
        app(CommitBookingQuote::class)->handle($first, Guest::factory()->create()->id);
        $this->assertStringNotContainsString($sessionId, json_encode($first->inputs, JSON_THROW_ON_ERROR));
        $this->assertSame(64, strlen((string) data_get($first->inputs, 'promotion_session_hash')));

        $second = app(BookingQuoteService::class)->create($this->quoteInput(
            $property->id, $plan->id, $category->id, now()->addDays(22), now()->addDays(23),
        ) + ['voucher_code' => 'session-only', 'promotion_session_id' => $sessionId]);
        try {
            app(CommitBookingQuote::class)->handle($second, Guest::factory()->create()->id);
            $this->fail('Expected per-session voucher rejection.');
        } catch (ValidationException $exception) {
            $this->assertSame('The promotion could not be applied.', $exception->errors()['voucher_code'][0]);
        }
    }

    public function test_voucher_is_reserved_with_hold_confirmed_once_and_reinstated_append_only_on_eligible_cancellation(): void
    {
        [, $property] = $this->tenantEnvironment();
        [$plan, , $category] = $this->baseRate($property->id, 10_000);
        $promotion = $this->promotion($property->id, 'One use', true, 'fixed', null, 2000, 1, true);
        $voucher = app(CommercialPromotionService::class)->issueVoucher($promotion, 'ONLY-ONE', ['usage_limit' => 1]);
        $guest = Guest::factory()->create();
        $quote = app(BookingQuoteService::class)->create($this->quoteInput($property->id, $plan->id, $category->id, now()->addDays(40), now()->addDays(42)) + ['voucher_code' => 'only-one']);
        $reservation = app(CommitBookingQuote::class)->handle($quote, $guest->id);
        $redemption = $voucher->redemptions()->firstOrFail();
        $this->assertSame('reserved', $redemption->state);
        $this->assertSame(18_000, app(FolioService::class)->summary($reservation)['balance_minor']);

        app(ReservationService::class)->confirm($reservation);
        app(ReservationService::class)->confirm($reservation->fresh());
        $this->assertSame('confirmed', $redemption->fresh()->state);
        $this->assertSame(1, $redemption->events()->where('type', 'confirmed')->count());

        $amended = app(AmendReservation::class)->handle($reservation->fresh(), [
            'rate_plan_id' => $plan->id, 'resource_category_id' => $category->id,
            'starts_at' => $reservation->starts_at, 'ends_at' => $reservation->ends_at->addDay(),
            'adults' => 1, 'children' => 0, 'infants' => 0,
        ], auth()->id());
        $this->assertSame(28_000, $amended->total_minor);
        $this->assertSame(1, $voucher->redemptions()->count());
        $this->assertSame($voucher->id, data_get($amended->price_snapshot, 'calculation.voucher_id'));

        app(ReservationService::class)->transition($amended, ReservationStatus::Cancelled, metadata: ['reason' => 'Eligible guest cancellation']);
        $this->assertSame('reinstated', $redemption->fresh()->state);
        $this->assertSame(['reserved', 'confirmed', 'reinstated'], $redemption->events()->pluck('type')->all());

        $quote->ratePlan->rules()->first()->update(['amount_minor' => 99_000]);
        $this->assertSame(18_000, $quote->fresh()->total_minor);
        $this->assertSame(3, VoucherRedemptionEvent::query()->count());
    }

    public function test_fiscal_source_is_an_immutable_non_issuance_snapshot(): void
    {
        [, $property] = $this->tenantEnvironment();
        [$plan, , $category] = $this->baseRate($property->id, 10_000);
        $quote = app(BookingQuoteService::class)->create($this->quoteInput($property->id, $plan->id, $category->id, now()->addDays(20), now()->addDays(21)));
        $reservation = app(CommitBookingQuote::class)->handle($quote, Guest::factory()->create()->id);
        $snapshot = app(FiscalSourceSnapshotFactory::class)->capture($reservation);
        $duplicate = app(FiscalSourceSnapshotFactory::class)->capture($reservation);
        $this->assertSame($snapshot->id, $duplicate->id);
        $this->assertSame('non_fiscal_operational_source', data_get($snapshot->source_snapshot, 'document_boundary'));
        $this->assertSame(hash('sha256', app(CanonicalJson::class)->encode($snapshot->source_snapshot)), $snapshot->checksum);

        $this->expectException(LogicException::class);
        $snapshot->update(['gross_minor' => 1]);
    }

    private function baseRate(string $propertyId, int $amount): array
    {
        $property = Property::query()->findOrFail($propertyId);
        $category = $this->category($property, 'room');
        Resource::factory()->create(['property_id' => $propertyId, 'category_id' => $category->id, 'capacity' => 10]);
        $plan = RatePlan::query()->create(['property_id' => $propertyId, 'name' => 'Commercial '.fake()->unique()->word(), 'currency' => 'USD', 'maximum_occupancy' => 10]);
        $rule = RateRule::query()->create(['rate_plan_id' => $plan->id, 'resource_category_id' => $category->id, 'amount_minor' => $amount]);

        return [$plan, $rule, $category];
    }

    private function promotion(string $propertyId, string $name, bool $code, string $type, ?int $bps, ?int $fixed, int $priority, bool $reinstate = false): CommercialPromotion
    {
        return CommercialPromotion::query()->create([
            'property_id' => $propertyId, 'name' => $name, 'public_label' => $name, 'state' => 'published',
            'currency' => 'USD', 'discount_type' => $type, 'percentage_basis_points' => $bps,
            'fixed_amount_minor' => $fixed, 'requires_code' => $code, 'priority' => $priority,
            'reinstate_on_cancel' => $reinstate, 'published_at' => now(), 'approval_owner_id' => auth()->id(),
        ]);
    }

    private function quoteInput(string $propertyId, string $planId, string $categoryId, mixed $starts, mixed $ends): array
    {
        return [
            'property_id' => $propertyId, 'rate_plan_id' => $planId, 'resource_category_id' => $categoryId,
            'starts_at' => $starts, 'ends_at' => $ends, 'adults' => 1, 'children' => 0, 'infants' => 0,
        ];
    }
}
