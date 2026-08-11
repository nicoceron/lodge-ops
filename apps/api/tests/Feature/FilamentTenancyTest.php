<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveFilamentTenant;
use App\Http\Middleware\ResolveTenant;
use App\Models\Membership;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Filament\Panel;
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

    public function test_inactive_tenants_and_other_panels_are_not_accessible(): void
    {
        [$tenant, , $user] = $this->tenantEnvironment(authenticate: false);

        $tenant->update(['is_active' => false]);

        $this->assertFalse($user->canAccessTenant($tenant));
        $this->assertFalse($user->canAccessPanel(filament()->getPanel('admin')));

        $tenant->update(['is_active' => true]);

        $this->assertFalse($user->canAccessPanel(Panel::make()->id('other')));
    }

    public function test_filament_middleware_explicitly_selects_the_membership_for_the_resolved_tenant(): void
    {
        [$firstTenant, , $user] = $this->tenantEnvironment(authenticate: false);
        $secondTenant = Tenant::factory()->create();
        app(TenantContext::class)->set($secondTenant);
        $secondMembership = Membership::factory()->create(['user_id' => $user->id]);
        app(TenantContext::class)->clear();
        $scopes = Membership::getAllGlobalScopes();
        Membership::setAllGlobalScopes([]);

        try {
            $this->actingAs($user);
            Filament::setCurrentPanel(filament()->getPanel('admin'));
            Filament::setTenant($secondTenant, isQuiet: true);
            $request = Request::create("/manage/workspace/{$secondTenant->slug}");
            $request->setUserResolver(fn () => $user);

            app(ResolveFilamentTenant::class)->handle($request, fn () => response()->noContent());

            $this->assertTrue(app(TenantContext::class)->membership()?->is($secondMembership));
            $this->assertFalse(app(TenantContext::class)->membership()?->tenant_id === $firstTenant->id);
        } finally {
            Membership::setAllGlobalScopes($scopes);
        }
    }

    public function test_api_middleware_explicitly_selects_the_membership_for_the_resolved_tenant(): void
    {
        [$firstTenant, , $user] = $this->tenantEnvironment(authenticate: false);
        $secondTenant = Tenant::factory()->create();
        app(TenantContext::class)->set($secondTenant);
        $secondMembership = Membership::factory()->create(['user_id' => $user->id]);
        app(TenantContext::class)->clear();
        $scopes = Membership::getAllGlobalScopes();
        Membership::setAllGlobalScopes([]);

        try {
            $request = Request::create('/api/v1/properties');
            $request->headers->set('X-Tenant-ID', $secondTenant->id);
            $request->setUserResolver(fn () => $user);

            app(ResolveTenant::class)->handle($request, function () use ($firstTenant, $secondMembership) {
                $this->assertTrue(app(TenantContext::class)->membership()?->is($secondMembership));
                $this->assertFalse(app(TenantContext::class)->membership()?->tenant_id === $firstTenant->id);

                return response()->noContent();
            });
        } finally {
            Membership::setAllGlobalScopes($scopes);
        }
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
