<?php

namespace Tests\Feature;

use App\Enums\CommunicationPurpose;
use App\Enums\FolioStatus;
use App\Enums\MembershipRole;
use App\Models\Allocation;
use App\Models\AutomationRule;
use App\Models\CalendarFeed;
use App\Models\Deposit;
use App\Models\ExchangeRate;
use App\Models\FolioLine;
use App\Models\GuestPortalAccessToken;
use App\Models\GuestPortalProfile;
use App\Models\Membership;
use App\Models\OperationalTask;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationNote;
use App\Models\Resource;
use App\Models\ServiceOccurrence;
use App\Models\Survey;
use App\Models\Tenant;
use App\Services\AvailabilityService;
use App\Services\Projections\FinanceProjectionService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_development_seed_provides_a_resettable_hashed_guest_preview_link(): void
    {
        $this->seed(DatabaseSeeder::class);

        $access = GuestPortalAccessToken::withoutGlobalScopes()->sole();
        $this->assertSame(hash('sha256', DatabaseSeeder::DEMO_GUEST_PORTAL_TOKEN), $access->token_hash);
        $this->assertNotSame(DatabaseSeeder::DEMO_GUEST_PORTAL_TOKEN, $access->token_hash);

        $this->postJson('/api/v1/guest-portal/exchange', [
            'token' => DatabaseSeeder::DEMO_GUEST_PORTAL_TOKEN,
        ])->assertOk()->assertJsonStructure(['data' => ['access_token', 'expires_at']]);

        $this->postJson('/api/v1/guest-portal/exchange', [
            'token' => DatabaseSeeder::DEMO_GUEST_PORTAL_TOKEN,
        ])->assertUnauthorized();

        $this->seed(DatabaseSeeder::class);

        $this->postJson('/api/v1/guest-portal/exchange', [
            'token' => DatabaseSeeder::DEMO_GUEST_PORTAL_TOKEN,
        ])->assertOk();
    }

    public function test_development_seed_populates_an_actionable_cross_module_demo(): void
    {
        CarbonImmutable::setTestNow('2026-08-11 15:00:00 UTC');
        $this->seed(DatabaseSeeder::class);
        $tenant = Tenant::query()->where('slug', 'demo-lodge')->firstOrFail();
        $membership = Membership::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('role', 'administrator')->firstOrFail();
        app(TenantContext::class)->set($tenant, $membership);

        $this->assertGreaterThanOrEqual(25, Reservation::query()->count());
        $this->assertGreaterThanOrEqual(3, ServiceOccurrence::query()->count());
        $this->assertGreaterThanOrEqual(5, OperationalTask::query()->count());
        $this->assertSame(count(MembershipRole::cases()), Membership::query()->distinct('role')->count('role'));
        $this->assertGreaterThanOrEqual(16, Payment::query()->count());
        $this->assertGreaterThanOrEqual(2, Deposit::query()->count());
        $this->assertTrue(Allocation::query()->whereHas('resource', fn ($query) => $query->where('is_buyout', true))->exists());
        $this->assertGreaterThanOrEqual(7, Reservation::query()->get('starts_at')->pluck('starts_at')->map->format('Y-m')->unique()->count());
        $this->assertGreaterThanOrEqual(3, Reservation::query()->whereNotNull('source')->distinct('source')->count('source'));
        $this->assertTrue(Allocation::query()->whereNotNull('requested_category_id')->whereNull('resource_id')->exists());
        $this->assertTrue(ReservationNote::query()->exists());
        $this->assertGreaterThanOrEqual(15, FolioLine::query()->count());
        $this->assertGreaterThanOrEqual(6, Reservation::query()->where('folio_status', FolioStatus::Closed)->count());
        $this->assertGreaterThanOrEqual(3, Resource::query()->whereNotNull('housekeeping_status')->count());
        $this->assertTrue(CalendarFeed::query()->where('is_active', true)->exists());
        $this->assertTrue(Organization::query()->exists());
        $this->assertTrue(Opportunity::query()->whereHas('activities')->whereNotNull('proposal_id')->exists());
        $this->assertTrue(GuestPortalProfile::query()->exists());
        $this->assertTrue(Survey::query()->whereNotNull('responded_at')->exists());
        $this->assertTrue(ExchangeRate::query()->where('base_currency', 'ARS')->where('quote_currency', 'USD')->exists());
        $this->assertTrue(Reservation::query()->where('currency', 'ARS')->whereNotNull('actual_end_at')->exists());
        $arrivalRule = AutomationRule::query()->where('trigger', 'reservation.arrival_approaching')->firstOrFail();
        $this->assertSame(
            CommunicationPurpose::PreArrival->value,
            data_get($arrivalRule->actions, '0.purpose'),
        );

        foreach (Allocation::query()->where('status', 'confirmed')->get() as $allocation) {
            app(AvailabilityService::class)->assertAvailable($allocation);
            $this->addToAssertionCount(1);
        }

        $finance = app(FinanceProjectionService::class)->build(
            CarbonImmutable::now($tenant->timezone)->startOfMonth()->utc(),
            CarbonImmutable::now($tenant->timezone)->addMonth()->startOfMonth()->utc(),
            'USD',
        );
        $this->assertCount(7, collect($finance['revenue_series'])->filter(fn (array $month): bool => $month['booked_minor'] > 0));
    }
}
