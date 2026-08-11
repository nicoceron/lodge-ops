<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class ApiTokenAuthenticationTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_staff_api_accepts_sanctum_bearer_tokens_without_a_browser_session(): void
    {
        [$tenant, $property, $user] = $this->tenantEnvironment(authenticate: false);
        $token = $user->createToken('integration-test')->plainTextToken;

        $this->withToken($token)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->getJson('/api/v1/properties')
            ->assertOk()
            ->assertJsonPath('data.0.id', $property->id);
    }

    public function test_staff_api_rejects_requests_without_a_bearer_token(): void
    {
        [$tenant] = $this->tenantEnvironment(authenticate: false);

        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->getJson('/api/v1/properties')
            ->assertUnauthorized();
    }

    public function test_staff_api_rejects_a_browser_session_without_a_bearer_token(): void
    {
        [$tenant, , $user] = $this->tenantEnvironment(authenticate: false);
        $this->actingAs($user);

        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->getJson('/api/v1/properties')
            ->assertUnauthorized();
    }
}
