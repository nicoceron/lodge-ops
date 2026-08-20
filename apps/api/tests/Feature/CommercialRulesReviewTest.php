<?php

namespace Tests\Feature;

use App\Contracts\Fiscal\FiscalSourceSnapshotFactory;
use App\Enums\FolioLineType;
use App\Enums\MembershipRole;
use App\Models\CommercialPromotion;
use App\Models\CommercialPromotionUsage;
use App\Models\Guest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\Resource;
use App\Models\ResourceCategory;
use App\Models\TaxRule;
use App\Models\VoucherRedemption;
use App\Services\BookingQuoteService;
use App\Services\CommercialPromotionService;
use App\Services\CommercialVersionPublisher;
use App\Services\CommitBookingQuote;
use App\Services\FolioService;
use App\Services\PaymentService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class CommercialRulesReviewTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_top_priority_governing_rule_blocks_without_falling_back_and_ctd_uses_local_dst_departure(): void
    {
        CarbonImmutable::setTestNow('2026-02-01T12:00:00Z');
        [, $property] = $this->tenantEnvironment();
        $property->update(['timezone' => 'America/New_York']);
        $category = $this->category($property, 'room');
        Resource::factory()->create(['property_id' => $property->id, 'category_id' => $category->id, 'capacity' => 4]);
        $plan = RatePlan::query()->create(['property_id' => $property->id, 'name' => 'DST rules', 'currency' => 'USD']);
        RateRule::query()->create(['rate_plan_id' => $plan->id, 'resource_category_id' => $category->id, 'amount_minor' => 10_000, 'priority' => 0]);
        $blocking = RateRule::query()->create([
            'rate_plan_id' => $plan->id, 'resource_category_id' => $category->id, 'amount_minor' => 99_000,
            'starts_on' => '2026-03-07', 'ends_on' => '2026-03-07', 'priority' => 100, 'blackout' => true,
        ]);
        $departureRule = RateRule::query()->create([
            'rate_plan_id' => $plan->id, 'resource_category_id' => $category->id,
            'amount_minor' => 10_000, 'starts_on' => '2026-03-09', 'ends_on' => '2026-03-09',
            'priority' => 200, 'closed_to_departure' => true,
        ]);
        DB::table('rate_plans')->where('id', $plan->id)->update(['state' => 'published', 'published_at' => now()]);
        $input = [
            'property_id' => $property->id, 'rate_plan_id' => $plan->id, 'resource_category_id' => $category->id,
            'starts_at' => '2026-03-08T04:30:00Z', // March 7, 23:30 local, immediately before DST.
            'ends_at' => '2026-03-10T03:30:00Z',   // March 9, 23:30 local, after DST.
            'adults' => 1,
        ];
        try {
            app(BookingQuoteService::class)->create($input);
            $this->fail('A lower-priority sellable rule must not bypass the governing blackout.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('rate_plan_id', $exception->errors());
        }

        DB::table('rate_rules')->where('id', $blocking->id)->update(['blackout' => false]);
        try {
            app(BookingQuoteService::class)->create($input);
            $this->fail('CTD must be evaluated on the local departure date.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('rate_plan_id', $exception->errors());
        }
        DB::table('rate_rules')->where('id', $departureRule->id)->update(['closed_to_departure' => false]);
        $quote = app(BookingQuoteService::class)->create($input);
        $this->assertSame('2026-03-07', data_get($quote->calculation_snapshot, 'business_dates.arrival'));
        $this->assertSame('2026-03-09', data_get($quote->calculation_snapshot, 'business_dates.departure'));
    }

    public function test_publisher_atomically_supersedes_and_quotes_reject_drafts_and_retired_versions(): void
    {
        [, $property] = $this->tenantEnvironment();
        $category = $this->category($property, 'room');
        Resource::factory()->create(['property_id' => $property->id, 'category_id' => $category->id]);
        $v1 = RatePlan::query()->create(['property_id' => $property->id, 'name' => 'Versioned', 'currency' => 'USD']);
        RateRule::query()->create(['rate_plan_id' => $v1->id, 'resource_category_id' => $category->id, 'amount_minor' => 10_000]);
        app(CommercialVersionPublisher::class)->publishRatePlan($v1, auth()->id());
        $v2 = $v1->replicate();
        $v2->version = 2;
        $v2->state = 'draft';
        $v2->supersedes_id = $v1->id;
        $v2->published_at = null;
        $v2->approved_by = null;
        $v2->save();
        RateRule::query()->create(['rate_plan_id' => $v2->id, 'resource_category_id' => $category->id, 'amount_minor' => 12_000]);
        app(CommercialVersionPublisher::class)->publishRatePlan($v2, auth()->id());
        $this->assertSame('retired', $v1->fresh()->state);
        $this->assertFalse($v1->fresh()->is_active);

        $input = ['property_id' => $property->id, 'resource_category_id' => $category->id, 'starts_at' => now()->addMonth(), 'ends_at' => now()->addMonth()->addDay(), 'adults' => 1];
        $this->assertSame(12_000, app(BookingQuoteService::class)->create($input + ['rate_plan_id' => $v2->id])->total_minor);
        $this->expectException(ModelNotFoundException::class);
        app(BookingQuoteService::class)->create($input + ['rate_plan_id' => $v1->id]);
    }

    public function test_each_promotion_has_its_own_locked_usage_and_voucher_charge(): void
    {
        [, $property] = $this->tenantEnvironment();
        [$plan, $category] = $this->publishedRate($property->id, 10_000);
        $auto = $this->promotion($property->id, 'Auto', false, 1000, 20, usageLimit: 1);
        $code = $this->promotion($property->id, 'Code', true, 2000, 10);
        $voucher = app(CommercialPromotionService::class)->issueVoucher($code, 'OWN-DISCOUNT');
        $quote = app(BookingQuoteService::class)->create([
            'property_id' => $property->id, 'rate_plan_id' => $plan->id, 'resource_category_id' => $category->id,
            'starts_at' => now()->addMonth(), 'ends_at' => now()->addMonth()->addDay(), 'adults' => 1, 'voucher_code' => 'own-discount',
        ]);
        app(CommitBookingQuote::class)->handle($quote, Guest::factory()->create()->id);
        $this->assertSame(1000, CommercialPromotionUsage::query()->where('commercial_promotion_id', $auto->id)->value('discount_minor'));
        $this->assertSame(2000, CommercialPromotionUsage::query()->where('commercial_promotion_id', $code->id)->value('discount_minor'));
        $this->assertSame(2000, VoucherRedemption::query()->where('voucher_id', $voucher->id)->value('discount_minor'));

        $second = app(BookingQuoteService::class)->create([
            'property_id' => $property->id, 'rate_plan_id' => $plan->id, 'resource_category_id' => $category->id,
            'starts_at' => now()->addMonths(2), 'ends_at' => now()->addMonths(2)->addDay(), 'adults' => 1,
        ]);
        $this->expectException(ValidationException::class);
        app(CommitBookingQuote::class)->handle($second, Guest::factory()->create()->id);
    }

    public function test_fixed_tax_requires_currency_and_mixed_currency_tax_is_not_selected(): void
    {
        [, $property] = $this->tenantEnvironment();
        [$plan, $category] = $this->publishedRate($property->id, 10_000);
        $missing = TaxRule::query()->create(['property_id' => $property->id, 'name' => 'Missing', 'calculation_type' => 'fixed', 'fixed_amount_minor' => 500]);
        try {
            app(CommercialVersionPublisher::class)->publishTaxRule($missing, auth()->id());
            $this->fail('A fixed tax without currency must not publish.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('currency', $exception->errors());
        }
        foreach ([['COP', 500], ['USD', 700]] as [$currency, $amount]) {
            $tax = TaxRule::query()->create([
                'property_id' => $property->id, 'name' => "Fixed {$currency}", 'calculation_type' => 'fixed',
                'fixed_amount_minor' => $amount,
            ]);
            $tax->currency = $currency;
            $tax->save();
            app(CommercialVersionPublisher::class)->publishTaxRule($tax, auth()->id());
        }
        $quote = app(BookingQuoteService::class)->create([
            'property_id' => $property->id, 'rate_plan_id' => $plan->id, 'resource_category_id' => $category->id,
            'starts_at' => now()->addMonth(), 'ends_at' => now()->addMonth()->addDay(), 'adults' => 1,
        ]);
        $this->assertSame(700, $quote->tax_minor);
        $this->assertSame('USD', data_get($quote->lines->firstWhere('type', 'tax')->metadata, 'currency'));
    }

    public function test_fiscal_snapshot_versions_locked_folio_extras_and_reversals(): void
    {
        [, $property] = $this->tenantEnvironment();
        [$plan, $category] = $this->publishedRate($property->id, 10_000);
        $quote = app(BookingQuoteService::class)->create([
            'property_id' => $property->id, 'rate_plan_id' => $plan->id, 'resource_category_id' => $category->id,
            'starts_at' => now()->addMonth(), 'ends_at' => now()->addMonth()->addDay(), 'adults' => 1,
        ]);
        $reservation = app(CommitBookingQuote::class)->handle($quote, Guest::factory()->create()->id);
        $first = app(FiscalSourceSnapshotFactory::class)->capture($reservation);
        $extra = app(FolioService::class)->append($reservation, FolioLineType::Charge, 'Extra', 1000, 2500, auth()->id());
        app(FolioService::class)->reverse($extra, 'Removed', auth()->id());
        $payment = app(PaymentService::class)->recordManual([
            'reservation_id' => $reservation->id, 'method' => 'bank_transfer', 'amount_minor' => 6000,
        ], auth()->id(), true);
        app(FolioService::class)->postProviderAdjustment($payment, FolioLineType::Refund, 'Partial refund', 1000, []);
        $second = app(FiscalSourceSnapshotFactory::class)->capture($reservation);
        $this->assertNotSame($first->source_revision, $second->source_revision);
        $this->assertSame(10_000, $second->gross_minor);
        $this->assertSame(6000, data_get($second->source_snapshot, 'settlement.payments_minor'));
        $this->assertSame(1000, data_get($second->source_snapshot, 'settlement.refunds_minor'));
        $this->assertSame(5000, data_get($second->source_snapshot, 'settlement.ledger_balance_minor'));
        $this->assertCount(5, data_get($second->source_snapshot, 'folio_lines'));
    }

    public function test_quote_explanation_policy_is_explicit_for_every_role_and_property_scope(): void
    {
        [$tenant, $property, $user, $membership] = $this->tenantEnvironment();
        [$plan, $category] = $this->publishedRate($property->id, 1000);
        $quote = app(BookingQuoteService::class)->create([
            'property_id' => $property->id, 'rate_plan_id' => $plan->id, 'resource_category_id' => $category->id,
            'starts_at' => now()->addMonth(), 'ends_at' => now()->addMonth()->addDay(), 'adults' => 1,
        ]);
        $allowed = [MembershipRole::Administrator, MembershipRole::Owner, MembershipRole::Manager, MembershipRole::Sales, MembershipRole::Finance];
        foreach (MembershipRole::cases() as $role) {
            $membership->update(['role' => $role]);
            app(TenantContext::class)->set($tenant, $membership->fresh());
            $this->assertSame(in_array($role, $allowed, true), Gate::forUser($user)->allows('view', $quote), $role->value);
        }
        $membership->update(['role' => MembershipRole::Sales, 'property_id' => $property->id]);
        app(TenantContext::class)->set($tenant, $membership->fresh());
        $this->assertTrue(Gate::forUser($user)->allows('view', $quote));
        $other = Property::factory()->create(['name' => 'Other']);
        DB::table('booking_quotes')->where('id', $quote->id)->update(['property_id' => $other->id]);
        $quote->refresh();
        $this->assertFalse(Gate::forUser($user)->allows('view', $quote));
    }

    public function test_commercial_migration_rollback_guard_is_actionable_after_version_use(): void
    {
        [, $property] = $this->tenantEnvironment();
        $used = RatePlan::query()->create(['property_id' => $property->id, 'name' => 'Used version', 'currency' => 'USD']);
        DB::table('rate_plans')->where('id', $used->id)->update(['version' => 2]);
        $migration = require database_path('migrations/2026_08_20_040001_add_commercial_rules_and_fiscal_readiness.php');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('reviewed data-collapse migration');
        $migration->down();
    }

    /** @return array{RatePlan, ResourceCategory} */
    private function publishedRate(string $propertyId, int $amount): array
    {
        $property = Property::query()->findOrFail($propertyId);
        $category = $this->category($property, 'room');
        Resource::factory()->create(['property_id' => $propertyId, 'category_id' => $category->id, 'capacity' => 4]);
        $plan = RatePlan::query()->create(['property_id' => $propertyId, 'name' => 'Review '.fake()->unique()->word(), 'currency' => 'USD']);
        RateRule::query()->create(['rate_plan_id' => $plan->id, 'resource_category_id' => $category->id, 'amount_minor' => $amount]);
        app(CommercialVersionPublisher::class)->publishRatePlan($plan, auth()->id());

        return [$plan->fresh(), $category];
    }

    private function promotion(string $propertyId, string $name, bool $code, int $fixed, int $priority, ?int $usageLimit = null): CommercialPromotion
    {
        return CommercialPromotion::query()->create([
            'property_id' => $propertyId, 'name' => $name, 'public_label' => $name, 'state' => 'published',
            'currency' => 'USD', 'discount_type' => 'fixed', 'fixed_amount_minor' => $fixed,
            'requires_code' => $code, 'priority' => $priority, 'usage_limit' => $usageLimit,
            'published_at' => now(), 'approval_owner_id' => auth()->id(),
        ]);
    }
}
