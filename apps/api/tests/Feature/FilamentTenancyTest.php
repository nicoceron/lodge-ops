<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class FilamentTenancyTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_filament_tenant_contract_only_exposes_active_memberships(): void
    {
        [$tenant, , $user] = $this->tenantEnvironment(authenticate: false);
        app(TenantContext::class)->clear();
        $unrelated = Tenant::factory()->create();

        $this->assertTrue($user->canAccessTenant($tenant));
        $this->assertFalse($user->canAccessTenant($unrelated));
        $this->assertTrue($user->canAccessPanel(filament()->getPanel('admin')));
        $this->assertSame([$tenant->id], $user->getTenants(filament()->getPanel('admin'))->pluck('id')->all());
    }
}
