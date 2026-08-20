<?php

namespace Tests\Feature;

use App\Models\CommercialPromotion;
use App\Models\CommercialPromotionUsage;
use App\Models\Guest;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\Resource;
use App\Models\TaxRule;
use App\Models\VoucherRedemption;
use App\Services\BookingQuoteService;
use App\Services\CommercialPromotionService;
use App\Services\CommitBookingQuote;
use App\Services\GuestMergeService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class CommercialMigrationCompatibilityTest extends TestCase
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

    public function test_legacy_active_versions_are_backfilled_without_inventing_fixed_tax_currency(): void
    {
        $path = 'database/migrations/2026_08_20_040001_add_commercial_rules_and_fiscal_readiness.php';
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]));
        [, $property] = $this->tenantEnvironment();
        $plan = RatePlan::query()->create(['property_id' => $property->id, 'name' => 'Legacy active', 'currency' => 'USD', 'is_active' => true]);
        $percentage = TaxRule::query()->create([
            'property_id' => $property->id, 'name' => 'Legacy percentage', 'calculation_type' => 'percentage',
            'percentage_basis_points' => 1900, 'is_active' => true,
        ]);
        $fixed = TaxRule::query()->create([
            'property_id' => $property->id, 'name' => 'Legacy fixed', 'calculation_type' => 'fixed',
            'fixed_amount_minor' => 500, 'is_active' => true,
        ]);

        $this->assertSame(0, Artisan::call('migrate', ['--path' => $path, '--force' => true]));
        $this->assertSame('published', $plan->fresh()->state);
        $this->assertNotNull($plan->fresh()->published_at);
        $this->assertSame('published', $percentage->fresh()->state);
        $this->assertSame('draft', $fixed->fresh()->state);
        $this->assertNull($fixed->fresh()->currency);
    }

    public function test_commercial_guest_history_foreign_keys_upgrade_round_trip_and_preserve_merge_identity(): void
    {
        $path = 'database/migrations/2026_08_20_040002_restrict_commercial_guest_history_foreign_keys.php';
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]));

        [, $property] = $this->tenantEnvironment();
        $guest = Guest::factory()->create(['email' => 'history-source@example.com', 'phone' => '+1000001']);
        $target = Guest::factory()->create(['email' => null, 'phone' => null]);
        $category = $this->category($property, 'room');
        Resource::factory()->create(['property_id' => $property->id, 'category_id' => $category->id, 'capacity' => 2]);
        $plan = RatePlan::query()->create(['property_id' => $property->id, 'name' => 'History migration', 'currency' => 'USD']);
        RateRule::query()->create(['rate_plan_id' => $plan->id, 'resource_category_id' => $category->id, 'amount_minor' => 10_000]);
        DB::table('rate_plans')->where('id', $plan->id)->update(['state' => 'published', 'published_at' => now()]);
        $promotion = CommercialPromotion::query()->create([
            'property_id' => $property->id, 'name' => 'History identity', 'public_label' => 'History identity',
            'state' => 'published', 'currency' => 'USD', 'discount_type' => 'fixed', 'fixed_amount_minor' => 1000,
            'requires_code' => true, 'published_at' => now(), 'approval_owner_id' => auth()->id(),
        ]);
        app(CommercialPromotionService::class)->issueVoucher($promotion, 'HISTORY-IDENTITY');
        $quote = app(BookingQuoteService::class)->create([
            'property_id' => $property->id, 'rate_plan_id' => $plan->id, 'resource_category_id' => $category->id,
            'starts_at' => now()->addMonth()->startOfDay()->addHours(15),
            'ends_at' => now()->addMonth()->startOfDay()->addHours(15)->addDays(2),
            'adults' => 1, 'children' => 0, 'infants' => 0, 'voucher_code' => 'history-identity',
        ]);
        $reservation = app(CommitBookingQuote::class)->handle($quote, $guest->id);
        $usageId = CommercialPromotionUsage::query()->where('reservation_id', $reservation->id)->soleValue('id');
        $redemptionId = VoucherRedemption::query()->where('reservation_id', $reservation->id)->soleValue('id');

        $this->assertSame(0, Artisan::call('migrate', ['--path' => $path, '--force' => true]));
        $this->assertDatabaseHas('commercial_promotion_usages', ['id' => $usageId, 'guest_id' => $guest->id]);
        $this->assertDatabaseHas('voucher_redemptions', ['id' => $redemptionId, 'guest_id' => $guest->id]);

        $this->assertSame(0, Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]));
        $this->assertDatabaseHas('commercial_promotion_usages', ['id' => $usageId, 'guest_id' => $guest->id]);
        $this->assertDatabaseHas('voucher_redemptions', ['id' => $redemptionId, 'guest_id' => $guest->id]);
        $this->assertSame(0, Artisan::call('migrate', ['--path' => $path, '--force' => true]));

        try {
            $guest->delete();
            $this->fail('A referenced historical guest must not be deleted.');
        } catch (QueryException) {
            // Both immutable histories must keep their original guest identity.
        }
        $this->assertDatabaseHas('guests', ['id' => $guest->id]);
        $this->assertDatabaseHas('commercial_promotion_usages', ['id' => $usageId, 'guest_id' => $guest->id]);
        $this->assertDatabaseHas('voucher_redemptions', ['id' => $redemptionId, 'guest_id' => $guest->id]);

        $merged = app(GuestMergeService::class)->merge($guest->fresh(), $target);
        $this->assertSame($target->id, $merged->id);
        $this->assertDatabaseHas('guests', ['id' => $guest->id, 'email' => null, 'merged_into_id' => $target->id]);
        $this->assertDatabaseHas('commercial_promotion_usages', ['id' => $usageId, 'guest_id' => $guest->id]);
        $this->assertDatabaseHas('voucher_redemptions', ['id' => $redemptionId, 'guest_id' => $guest->id]);
        $this->assertDatabaseMissing('commercial_promotion_usages', ['id' => $usageId, 'guest_id' => $target->id]);
        $this->assertDatabaseMissing('voucher_redemptions', ['id' => $redemptionId, 'guest_id' => $target->id]);
    }
}
