<?php

namespace Tests\Feature;

use App\Models\GuestPortalAccessToken;
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
}
