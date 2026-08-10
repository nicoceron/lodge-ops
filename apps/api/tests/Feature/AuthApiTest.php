<?php

namespace Tests\Feature;

use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FAQRCode\Google2FA;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_stateful_login_returns_only_the_users_active_tenants(): void
    {
        [$tenant, , $user] = $this->tenantEnvironment(authenticate: false);
        app(TenantContext::class)->clear();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.tenants.0.id', $tenant->id);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_invalid_login_does_not_reveal_whether_the_account_exists(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'incorrect'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email')
            ->assertJsonPath('message', 'The provided credentials are invalid.');
    }

    public function test_unverified_accounts_cannot_open_a_staff_session(): void
    {
        [, , $user] = $this->tenantEnvironment(authenticate: false);
        $user->forceFill(['email_verified_at' => null])->save();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    public function test_totp_enabled_accounts_require_and_verify_a_second_factor(): void
    {
        [, , $user] = $this->tenantEnvironment(authenticate: false);
        $google2fa = app(Google2FA::class);
        $secret = $google2fa->generateSecretKey();
        $user->saveAppAuthenticationSecret($secret);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonPath('mfa_required', true)
            ->assertJsonValidationErrors('mfa_code');
        $this->assertGuest();

        $code = $google2fa->getCurrentOtp($secret);
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'mfa_code' => $code,
        ])->assertOk()->assertJsonPath('data.id', $user->id);

        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'mfa_code' => $code,
        ])->assertUnprocessable()->assertJsonPath('mfa_required', true);
    }

    public function test_recovery_codes_are_single_use(): void
    {
        [, , $user] = $this->tenantEnvironment(authenticate: false);
        $user->saveAppAuthenticationSecret(app(Google2FA::class)->generateSecretKey());
        $user->saveAppAuthenticationRecoveryCodes([Hash::make('recovery-code-123')]);

        $payload = ['email' => $user->email, 'password' => 'password', 'recovery_code' => 'recovery-code-123'];
        $this->postJson('/api/v1/auth/login', $payload)->assertOk();
        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->postJson('/api/v1/auth/login', $payload)->assertUnprocessable()->assertJsonPath('mfa_required', true);
    }

    public function test_password_recovery_is_non_enumerating_and_resets_with_a_single_use_token(): void
    {
        Notification::fake();
        [, , $user] = $this->tenantEnvironment(authenticate: false);
        $message = 'If the account exists, a password reset link is on its way.';
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk()->assertJsonPath('message', $message);
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'missing@example.com'])->assertOk()->assertJsonPath('message', $message);
        $token = null;
        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });

        $payload = [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-new-secure-password',
            'password_confirmation' => 'a-new-secure-password',
        ];
        $this->postJson('/api/v1/auth/reset-password', $payload)->assertOk();
        $this->assertTrue(Hash::check('a-new-secure-password', $user->fresh()->password));
        $this->postJson('/api/v1/auth/reset-password', $payload)->assertUnprocessable();
    }
}
