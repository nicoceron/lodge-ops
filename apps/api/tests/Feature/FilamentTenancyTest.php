<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveFilamentTenant;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class FilamentTenancyTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        Filament::setTenant(null, isQuiet: true);
        Filament::setCurrentPanel(null);

        parent::tearDown();
    }

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

    public function test_filament_tenant_context_survives_the_persistent_livewire_middleware_pipeline(): void
    {
        [$tenant, , $user, $membership] = $this->tenantEnvironment(authenticate: false);
        app(TenantContext::class)->clear();
        $this->actingAs($user);
        Filament::setCurrentPanel(filament()->getPanel('admin'));
        Filament::setTenant($tenant, isQuiet: true);

        $request = Request::create("/manage/workspace/{$tenant->slug}");
        $request->setUserResolver(fn () => $user);

        $response = app(ResolveFilamentTenant::class)->handle(
            $request,
            fn () => response()->noContent(),
        );

        $this->assertSame(204, $response->getStatusCode());
        $this->assertTrue(app(TenantContext::class)->tenant()->is($tenant));
        $this->assertTrue(app(TenantContext::class)->membership()?->is($membership));
    }
}
