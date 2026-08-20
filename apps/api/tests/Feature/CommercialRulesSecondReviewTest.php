<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Filament\Resources\CommercialPromotions\Pages\ManageCommercialPromotions;
use App\Filament\Resources\RatePlans\Pages\ManageRatePlans;
use App\Filament\Resources\TaxRules\Pages\ManageTaxRules;
use App\Filament\Resources\Vouchers\Pages\ListVouchers;
use App\Models\BookingQuote;
use App\Models\CancellationPolicy;
use App\Models\CommercialPromotion;
use App\Models\CommercialPromotionUsage;
use App\Models\DepositPolicy;
use App\Models\Guest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\Resource;
use App\Models\ResourceCategory;
use App\Models\TaxRule;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Services\AmendReservation;
use App\Services\BookingQuoteService;
use App\Services\CancelReservation;
use App\Services\CommercialPromotionService;
use App\Services\CommitBookingQuote;
use App\Services\CompleteRefund;
use App\Services\PaymentService;
use App\Services\QuoteExplanationService;
use App\Services\RequestRefund;
use App\Services\ReservationService;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use LogicException;
use RuntimeException;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class CommercialRulesSecondReviewTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        Filament::setTenant(null, isQuiet: true);
        Filament::setCurrentPanel(null);
        parent::tearDown();
    }

    public function test_every_commercial_action_requires_configuration_write_for_every_role(): void
    {
        [$tenant, $property, $user, $membership] = $this->tenantEnvironment(authenticate: false);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($tenant, isQuiet: true);
        [$draftPlan, $publishedPlan, $draftPromotion, $publishedPromotion, $draftTax, $publishedTax, $activeVoucher, $suspendedVoucher] = $this->authorizationFixtures($property);

        foreach (MembershipRole::cases() as $role) {
            $membership->update(['role' => $role]);
            app(TenantContext::class)->set($tenant, $membership->fresh());
            $expected = in_array($role, [MembershipRole::Administrator, MembershipRole::Manager], true);
            foreach ([$draftPlan, $draftPromotion, $draftTax, $activeVoucher] as $record) {
                $this->assertSame($expected, Gate::forUser($user)->allows('manageConfiguration', $record), $role->value.' '.$record::class);
            }
        }

        foreach ([MembershipRole::Sales, MembershipRole::Operations] as $role) {
            $membership->update(['role' => $role]);
            app(TenantContext::class)->set($tenant, $membership->fresh());
            Livewire::test(ManageRatePlans::class)
                ->assertTableActionHidden('publish', $draftPlan)
                ->assertTableActionHidden('copyVersion', $publishedPlan)
                ->assertTableActionHidden('retire', $publishedPlan);
            Livewire::test(ManageCommercialPromotions::class)
                ->assertTableActionHidden('publish', $draftPromotion)
                ->assertTableActionHidden('copyVersion', $publishedPromotion)
                ->assertTableActionHidden('retire', $publishedPromotion);
            Livewire::test(ManageTaxRules::class)
                ->assertTableActionHidden('publish', $draftTax)
                ->assertTableActionHidden('copyVersion', $publishedTax)
                ->assertTableActionHidden('retire', $publishedTax);
            Livewire::test(ListVouchers::class)
                ->assertTableActionHidden('suspend', $activeVoucher)
                ->assertTableActionHidden('reactivate', $suspendedVoucher)
                ->assertTableActionHidden('retire', $activeVoucher);
        }

        $this->assertSame('draft', $draftPlan->fresh()->state);
        $this->assertSame('published', $publishedPlan->fresh()->state);
        $this->assertSame('draft', $draftPromotion->fresh()->state);
        $this->assertSame('published', $publishedPromotion->fresh()->state);
        $this->assertSame('draft', $draftTax->fresh()->state);
        $this->assertSame('published', $publishedTax->fresh()->state);
        $this->assertSame('active', $activeVoucher->fresh()->state);
        $this->assertSame('suspended', $suspendedVoucher->fresh()->state);
    }

    public function test_staff_session_identity_is_stable_and_reused_by_hold_and_amendment(): void
    {
        [, $property] = $this->tenantEnvironment();
        [$plan, $category] = $this->publishedRate($property, 10_000);
        $promotion = CommercialPromotion::query()->create([
            'property_id' => $property->id, 'name' => 'Staff session', 'public_label' => 'Staff session',
            'state' => 'published', 'currency' => 'USD', 'discount_type' => 'fixed', 'fixed_amount_minor' => 1000,
            'per_session_limit' => 1, 'published_at' => now(), 'approval_owner_id' => auth()->id(),
        ]);
        $session = app('session')->driver();
        $session->start();
        $request = Request::create('/manage');
        $request->setLaravelSession($session);
        app()->instance('request', $request);

        $quote = app(BookingQuoteService::class)->create($this->quoteInput($property, $plan, $category));
        $rawSessionId = $session->get('commercial_promotion_session_id');
        $sessionHash = data_get($quote->calculation_snapshot, 'promotion_session_hash');
        $this->assertIsString($rawSessionId);
        $this->assertSame(64, strlen((string) $sessionHash));
        $this->assertStringNotContainsString($rawSessionId, json_encode([$quote->inputs, $quote->calculation_snapshot], JSON_THROW_ON_ERROR));

        $reservation = app(CommitBookingQuote::class)->handle($quote, Guest::factory()->create()->id);
        $amended = app(AmendReservation::class)->handle($reservation, [
            'rate_plan_id' => $plan->id, 'resource_category_id' => $category->id,
            'starts_at' => $reservation->starts_at, 'ends_at' => $reservation->ends_at->addDay(),
            'adults' => 1, 'children' => 0, 'infants' => 0,
        ], auth()->id());
        $replacement = $amended->bookingQuote;

        $this->assertSame($sessionHash, data_get($replacement->calculation_snapshot, 'promotion_session_hash'));
        $this->assertSame(2, CommercialPromotionUsage::query()->where('commercial_promotion_id', $promotion->id)->count());
        $this->assertSame('released', CommercialPromotionUsage::query()
            ->where('commercial_promotion_id', $promotion->id)
            ->where('booking_quote_id', $quote->id)
            ->value('state'));
        $this->assertSame('reserved', CommercialPromotionUsage::query()
            ->where('commercial_promotion_id', $promotion->id)
            ->where('booking_quote_id', $replacement->id)
            ->value('state'));
    }

    public function test_v1_quote_rollback_guard_runs_before_any_destructive_ddl(): void
    {
        [, $property] = $this->tenantEnvironment();
        [$plan, $category] = $this->publishedRate($property, 10_000);
        $quote = app(BookingQuoteService::class)->create($this->quoteInput($property, $plan, $category));
        $lineCount = $quote->lines()->count();
        $migration = require database_path('migrations/2026_08_20_040001_add_commercial_rules_and_fiscal_readiness.php');

        try {
            $migration->down();
            $this->fail('The immutable v1 quote must block rollback.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('No DDL was changed', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('commercial_promotion_usages'));
        $this->assertTrue(Schema::hasColumn('booking_quotes', 'calculation_snapshot'));
        $this->assertTrue(Schema::hasColumn('booking_quote_lines', 'explanation'));
        $this->assertDatabaseHas('booking_quotes', ['id' => $quote->id, 'checksum' => $quote->checksum]);
        $this->assertSame($lineCount, DB::table('booking_quote_lines')->where('booking_quote_id', $quote->id)->count());
    }

    public function test_refund_policy_is_audited_idempotently_and_complete_history_is_explained(): void
    {
        foreach ([true, false] as $reinstate) {
            [, $property] = $this->tenantEnvironment();
            [$plan, $category] = $this->publishedRate($property, 20_000, withPolicies: true);
            $promotion = CommercialPromotion::query()->create([
                'property_id' => $property->id, 'name' => 'Refund '.($reinstate ? 'reinstates' : 'retains'),
                'public_label' => 'Refund policy', 'state' => 'published', 'currency' => 'USD',
                'discount_type' => 'fixed', 'fixed_amount_minor' => 2000, 'requires_code' => true,
                'reinstate_on_cancel' => $reinstate, 'published_at' => now(), 'approval_owner_id' => auth()->id(),
            ]);
            $voucher = app(CommercialPromotionService::class)->issueVoucher($promotion, $reinstate ? 'REFUND-REINSTATE' : 'REFUND-RETAIN');
            $quote = app(BookingQuoteService::class)->create($this->quoteInput($property, $plan, $category) + [
                'voucher_code' => $reinstate ? 'refund-reinstate' : 'refund-retain',
            ]);
            $reservation = app(CommitBookingQuote::class)->handle($quote, Guest::factory()->create()->id);
            app(ReservationService::class)->confirm($reservation);
            $reservation = app(AmendReservation::class)->handle($reservation, [
                'rate_plan_id' => $plan->id, 'resource_category_id' => $category->id,
                'starts_at' => $reservation->starts_at, 'ends_at' => $reservation->ends_at->addDay(),
                'adults' => 1, 'children' => 0, 'infants' => 0,
            ], auth()->id());
            $payment = app(PaymentService::class)->recordManual([
                'reservation_id' => $reservation->id, 'method' => 'bank_transfer', 'amount_minor' => $reservation->total_minor,
            ], auth()->id(), true);
            $cancelled = app(CancelReservation::class)->handle($reservation, 'Refund lifecycle review', auth()->id());
            $request = app(RequestRefund::class)->handle($cancelled, $payment, $payment->amount_minor, 'Return guest credit', auth()->id());
            $completed = app(CompleteRefund::class)->handle($request, 'review-refund-'.$reinstate, auth()->id());
            $duplicate = app(CompleteRefund::class)->handle($request, 'review-refund-'.$reinstate, auth()->id());
            $eventType = $reinstate ? 'refund_reinstated' : 'refund_retained';

            $this->assertSame($completed->id, $duplicate->id);
            $this->assertSame(1, $request->events()->where('type', 'refund_completed')->count());
            $this->assertGreaterThan(0, CommercialPromotionUsage::query()->where('reservation_id', $reservation->id)
                ->whereHas('events', fn ($query) => $query->where('type', $eventType))->count());
            $this->assertGreaterThan(0, VoucherRedemption::query()->where('reservation_id', $reservation->id)
                ->whereHas('events', fn ($query) => $query->where('type', $eventType))->count());
            $this->assertSame($reinstate ? 'released' : 'confirmed', VoucherRedemption::query()
                ->where('booking_quote_id', $reservation->booking_quote_id)->value('state'));

            $history = app(QuoteExplanationService::class)->project(BookingQuote::query()->findOrFail($reservation->booking_quote_id));
            $this->assertNotEmpty($history['lines']);
            $this->assertSame(1, data_get($history, 'lines.0.rule_facts.rate_rule_version'));
            $this->assertSame('Review deposit', data_get($history, 'deposit_policy.name'));
            $this->assertSame('Review cancellation', data_get($history, 'cancellation_policy.name'));
            $this->assertCount(2, $history['quote_history']);
            $this->assertNotEmpty(data_get($history, 'quote_history.0.lines'));
            $this->assertSame('Review deposit', data_get($history, 'quote_history.0.deposit_policy.name'));
            $this->assertSame('Review cancellation', data_get($history, 'quote_history.1.cancellation_policy.name'));
            $this->assertGreaterThanOrEqual(2, count($history['promotion_usage_history']));
            $this->assertGreaterThanOrEqual(2, count($history['voucher_redemption_history']));
            $this->assertContains($eventType, collect($history['voucher_redemption_history'])->flatMap(fn (array $redemption): array => array_column($redemption['events'], 'type'))->all());
        }
    }

    public function test_voucher_redemption_facts_are_immutable_while_lifecycle_transitions_remain_valid(): void
    {
        [, $property] = $this->tenantEnvironment();
        [$plan, $category] = $this->publishedRate($property, 10_000);
        $promotion = CommercialPromotion::query()->create([
            'property_id' => $property->id, 'name' => 'Immutable redemption', 'public_label' => 'Immutable redemption',
            'state' => 'published', 'currency' => 'USD', 'discount_type' => 'fixed', 'fixed_amount_minor' => 1000,
            'requires_code' => true, 'published_at' => now(), 'approval_owner_id' => auth()->id(),
        ]);
        app(CommercialPromotionService::class)->issueVoucher($promotion, 'IMMUTABLE-REDEMPTION');
        $quote = app(BookingQuoteService::class)->create($this->quoteInput($property, $plan, $category) + [
            'voucher_code' => 'immutable-redemption',
            'promotion_session_id' => 'immutable-redemption-session',
        ]);
        $reservation = app(CommitBookingQuote::class)->handle($quote, Guest::factory()->create()->id);
        $redemption = VoucherRedemption::query()->where('reservation_id', $reservation->id)->firstOrFail();

        $mutations = [
            'voucher_id' => (string) Str::uuid(),
            'booking_quote_id' => (string) Str::uuid(),
            'reservation_id' => (string) Str::uuid(),
            'guest_id' => null,
            'session_key_hash' => str_repeat('f', 64),
            'command_id' => (string) Str::uuid(),
            'currency' => 'EUR',
            'discount_minor' => $redemption->discount_minor + 1,
            'reserved_at' => $redemption->reserved_at->addSecond(),
        ];
        foreach ($mutations as $field => $value) {
            $original = $redemption->fresh()->getRawOriginal($field);
            try {
                $redemption->fresh()->forceFill([$field => $value])->save();
                $this->fail("Voucher redemption {$field} must be immutable.");
            } catch (LogicException $exception) {
                $this->assertSame('Voucher redemption facts are immutable.', $exception->getMessage());
            }
            $this->assertSame($original, $redemption->fresh()->getRawOriginal($field));
        }

        app(CommercialPromotionService::class)->confirm($reservation);
        $this->assertSame('confirmed', $redemption->fresh()->state);
        $this->assertNotNull($redemption->fresh()->confirmed_at);
        app(CommercialPromotionService::class)->release($reservation, 'Valid lifecycle regression', false);
        $this->assertSame('released', $redemption->fresh()->state);
        $this->assertNotNull($redemption->fresh()->released_at);
        $this->assertSame(['reserved', 'confirmed', 'released'], $redemption->events()->pluck('type')->all());

        try {
            $redemption->fresh()->delete();
            $this->fail('Voucher redemption deletion must be rejected.');
        } catch (LogicException $exception) {
            $this->assertSame('Voucher redemption facts are immutable.', $exception->getMessage());
        }
        $this->assertDatabaseHas('voucher_redemptions', ['id' => $redemption->id]);
    }

    /** @return array{RatePlan, RatePlan, CommercialPromotion, CommercialPromotion, TaxRule, TaxRule, Voucher, Voucher} */
    private function authorizationFixtures(Property $property): array
    {
        $draftPlan = RatePlan::query()->create(['property_id' => $property->id, 'name' => 'Draft action', 'currency' => 'USD']);
        $publishedPlan = RatePlan::query()->create(['property_id' => $property->id, 'name' => 'Published action', 'currency' => 'USD']);
        DB::table('rate_plans')->where('id', $publishedPlan->id)->update(['state' => 'published', 'published_at' => now()]);
        $draftPromotion = CommercialPromotion::query()->create([
            'property_id' => $property->id, 'name' => 'Draft promotion', 'public_label' => 'Draft',
            'currency' => 'USD', 'discount_type' => 'fixed', 'fixed_amount_minor' => 100,
        ]);
        $publishedPromotion = CommercialPromotion::query()->create([
            'property_id' => $property->id, 'name' => 'Published promotion', 'public_label' => 'Published',
            'state' => 'published', 'currency' => 'USD', 'discount_type' => 'fixed', 'fixed_amount_minor' => 100,
            'requires_code' => true, 'published_at' => now(),
        ]);
        $draftTax = TaxRule::query()->create(['property_id' => $property->id, 'name' => 'Draft tax', 'calculation_type' => 'percentage', 'percentage_basis_points' => 100]);
        $publishedTax = TaxRule::query()->create(['property_id' => $property->id, 'name' => 'Published tax', 'calculation_type' => 'percentage', 'percentage_basis_points' => 100]);
        DB::table('tax_rules')->where('id', $publishedTax->id)->update(['state' => 'published', 'published_at' => now()]);
        $activeVoucher = app(CommercialPromotionService::class)->issueVoucher($publishedPromotion, 'ACTIVE-ACTION');
        $suspendedVoucher = app(CommercialPromotionService::class)->issueVoucher($publishedPromotion, 'SUSPENDED-ACTION');
        $suspendedVoucher->update(['state' => 'suspended']);

        return [$draftPlan, $publishedPlan->fresh(), $draftPromotion, $publishedPromotion, $draftTax, $publishedTax->fresh(), $activeVoucher, $suspendedVoucher];
    }

    /** @return array{RatePlan, ResourceCategory} */
    private function publishedRate(Property $property, int $amount, bool $withPolicies = false): array
    {
        $category = $this->category($property, 'room');
        Resource::factory()->create(['property_id' => $property->id, 'category_id' => $category->id, 'capacity' => 4]);
        $deposit = $withPolicies ? DepositPolicy::query()->create([
            'property_id' => $property->id, 'name' => 'Review deposit', 'requirement_type' => 'none', 'is_default' => false,
        ]) : null;
        $cancellation = $withPolicies ? CancellationPolicy::query()->create([
            'property_id' => $property->id, 'name' => 'Review cancellation', 'summary' => 'Full refund for this test.', 'is_default' => false,
        ]) : null;
        $plan = RatePlan::query()->create([
            'property_id' => $property->id, 'name' => 'Second review '.fake()->unique()->word(), 'currency' => 'USD',
            'deposit_policy_id' => $deposit?->id, 'cancellation_policy_id' => $cancellation?->id,
        ]);
        RateRule::query()->create(['rate_plan_id' => $plan->id, 'resource_category_id' => $category->id, 'amount_minor' => $amount]);
        DB::table('rate_plans')->where('id', $plan->id)->update(['state' => 'published', 'published_at' => now()]);

        return [$plan->fresh(), $category];
    }

    /** @return array<string, mixed> */
    private function quoteInput(Property $property, RatePlan $plan, ResourceCategory $category): array
    {
        return [
            'property_id' => $property->id, 'rate_plan_id' => $plan->id, 'resource_category_id' => $category->id,
            'starts_at' => now()->addMonth()->startOfDay()->addHours(15),
            'ends_at' => now()->addMonth()->startOfDay()->addHours(15)->addDays(2),
            'adults' => 1, 'children' => 0, 'infants' => 0,
        ];
    }
}
