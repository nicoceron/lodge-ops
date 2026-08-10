<?php

namespace Tests\Feature;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
