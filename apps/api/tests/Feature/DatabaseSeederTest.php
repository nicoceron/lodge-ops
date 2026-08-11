<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Models\Allocation;
use App\Models\Deposit;
use App\Models\GuestPortalAccessToken;
use App\Models\Membership;
use App\Models\OperationalTask;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ServiceOccurrence;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

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
        $this->seed(DatabaseSeeder::class);
        $tenant = Tenant::query()->where('slug', 'demo-lodge')->firstOrFail();
        $membership = Membership::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('role', 'administrator')->firstOrFail();
        app(TenantContext::class)->set($tenant, $membership);

        $this->assertGreaterThanOrEqual(4, Reservation::query()->count());
        $this->assertGreaterThanOrEqual(3, ServiceOccurrence::query()->count());
        $this->assertGreaterThanOrEqual(5, OperationalTask::query()->count());
        $this->assertSame(count(MembershipRole::cases()), Membership::query()->distinct('role')->count('role'));
        $this->assertGreaterThanOrEqual(2, Payment::query()->count());
        $this->assertGreaterThanOrEqual(2, Deposit::query()->count());
        $this->assertTrue(Allocation::query()->whereHas('resource', fn ($query) => $query->where('is_buyout', true))->exists());
    }
}
