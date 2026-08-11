<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class FilamentAuthenticationTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_filament_owns_staff_login_and_password_recovery(): void
    {
        $this->get('/')->assertRedirect('/manage');

        $this->get('/manage/login')
            ->assertOk()
            ->assertSee('Sign in');

        $this->get('/manage/password-reset/request')
            ->assertOk()
            ->assertSee('Forgot password?');
    }

    public function test_staff_navigation_uses_filament_spa_mode(): void
    {
        $this->assertTrue(
            Filament::getPanel('admin')->hasSpaMode(),
            'Authenticated Filament navigation should not perform a full document reload for every internal click.',
        );
    }

    public function test_filament_exposes_the_database_notification_inbox(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertTrue($panel->hasDatabaseNotifications());
        $this->assertSame('30s', $panel->getDatabaseNotificationsPollingInterval());
    }

    public function test_unverified_staff_use_filaments_native_email_verification_flow(): void
    {
        [$tenant, , $user] = $this->tenantEnvironment(authenticate: false);
        $user->forceFill(['email_verified_at' => null])->save();
        $panel = Filament::getPanel('admin');

        $this->assertTrue($panel->hasEmailVerification());
        $this->assertTrue($user->canAccessPanel($panel));

        $this->actingAs($user)
            ->get("/manage/workspace/{$tenant->slug}")
            ->assertRedirect('/manage/email-verification/prompt');
    }
}
