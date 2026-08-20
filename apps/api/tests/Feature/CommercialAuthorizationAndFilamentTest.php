<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Filament\Resources\CommercialPromotions\CommercialPromotionResource;
use App\Filament\Resources\CommercialPromotions\Pages\ManageCommercialPromotions;
use App\Filament\Resources\RatePlans\Pages\ManageRatePlans;
use App\Filament\Resources\RatePlans\RatePlanResource;
use App\Filament\Resources\Vouchers\VoucherResource;
use App\Models\CommercialPromotion;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\Resource;
use App\Services\BookingQuoteService;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class CommercialAuthorizationAndFilamentTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        Filament::setTenant(null, isQuiet: true);
        Filament::setCurrentPanel(null);
        parent::tearDown();
    }

    public function test_manager_can_publish_versions_and_commercial_resources_render(): void
    {
        [$tenant, $property, $user] = $this->tenantEnvironment(MembershipRole::Manager, authenticate: false);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($tenant, isQuiet: true);
        $category = $this->category($property, 'room');
        $plan = RatePlan::query()->create(['property_id' => $property->id, 'name' => 'Publish me', 'currency' => 'USD']);
        RateRule::query()->create(['rate_plan_id' => $plan->id, 'resource_category_id' => $category->id, 'amount_minor' => 10_000]);
        $promotion = CommercialPromotion::query()->create([
            'property_id' => $property->id, 'name' => 'Publish offer', 'public_label' => 'Publish offer',
            'currency' => 'USD', 'discount_type' => 'fixed', 'fixed_amount_minor' => 1000, 'requires_code' => true,
        ]);

        $this->get(RatePlanResource::getUrl('index', ['tenant' => $tenant]))->assertOk()->assertSee('Publish me');
        $this->get(CommercialPromotionResource::getUrl('index', ['tenant' => $tenant]))->assertOk()->assertSee('Publish offer');
        $this->get(VoucherResource::getUrl('index', ['tenant' => $tenant]))->assertOk();
        Livewire::test(ManageRatePlans::class)->callTableAction('publish', $plan)->assertHasNoErrors();
        Livewire::test(ManageCommercialPromotions::class)->callTableAction('publish', $promotion)->assertHasNoErrors();
        $this->assertSame('published', $plan->fresh()->state);
        $this->assertSame('published', $promotion->fresh()->state);
        $this->assertSame($user->id, $promotion->fresh()->approval_owner_id);
    }

    public function test_quote_explanation_is_property_and_tenant_scoped_and_viewer_cannot_quote(): void
    {
        [$tenant, $property, $user, $membership] = $this->tenantEnvironment();
        $category = $this->category($property, 'room');
        Resource::factory()->create(['property_id' => $property->id, 'category_id' => $category->id]);
        $plan = RatePlan::query()->create(['property_id' => $property->id, 'name' => 'API rate', 'currency' => 'USD']);
        RateRule::query()->create(['rate_plan_id' => $plan->id, 'resource_category_id' => $category->id, 'amount_minor' => 10_000]);
        DB::table('rate_plans')->where('id', $plan->id)->update(['state' => 'published', 'published_at' => now()]);
        $headers = ['X-Tenant-ID' => $tenant->id, 'Idempotency-Key' => (string) Str::uuid()];
        $payload = [
            'property_id' => $property->id, 'rate_plan_id' => $plan->id, 'resource_category_id' => $category->id,
            'starts_at' => now()->addDays(30)->toIso8601String(), 'ends_at' => now()->addDays(32)->toIso8601String(), 'adults' => 1,
        ];
        $quoteId = $this->withHeaders($headers)->postJson('/api/v1/booking-quotes', $payload)->assertCreated()->json('data.id');
        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson("/api/v1/booking-quotes/{$quoteId}")
            ->assertOk()->assertJsonPath('data.historical_projection', true)->assertJsonPath('data.total_minor', 20_000);

        [, $otherProperty] = $this->tenantEnvironment();
        $otherCategory = $this->category($otherProperty, 'room');
        Resource::factory()->create(['property_id' => $otherProperty->id, 'category_id' => $otherCategory->id]);
        $otherPlan = RatePlan::query()->create(['property_id' => $otherProperty->id, 'name' => 'Other', 'currency' => 'USD']);
        RateRule::query()->create(['rate_plan_id' => $otherPlan->id, 'resource_category_id' => $otherCategory->id, 'amount_minor' => 1000]);
        DB::table('rate_plans')->where('id', $otherPlan->id)->update(['state' => 'published', 'published_at' => now()]);
        $otherQuote = app(BookingQuoteService::class)->create([
            'property_id' => $otherProperty->id, 'rate_plan_id' => $otherPlan->id, 'resource_category_id' => $otherCategory->id,
            'starts_at' => now()->addDays(30), 'ends_at' => now()->addDays(31), 'adults' => 1,
        ]);

        app(TenantContext::class)->set($tenant, $membership);
        Sanctum::actingAs($user);
        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson("/api/v1/booking-quotes/{$otherQuote->id}")->assertNotFound();

        [$viewerTenant, $viewerProperty] = $this->tenantEnvironment(MembershipRole::Viewer);
        $this->withHeaders(['X-Tenant-ID' => $viewerTenant->id, 'Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/booking-quotes', $payload + ['property_id' => $viewerProperty->id])->assertForbidden();
    }
}
