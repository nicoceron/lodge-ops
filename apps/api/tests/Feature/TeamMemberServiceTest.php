<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Models\Membership;
use App\Models\Property;
use App\Models\Tenant;
use App\Services\TeamMemberService;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class TeamMemberServiceTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    public function test_administrator_can_invite_a_property_scoped_team_member(): void
    {
        Notification::fake();
        [$tenant, $property] = $this->tenantEnvironment(authenticate: false);

        $membership = app(TeamMemberService::class)->invite(
            '  Ana Guide  ',
            'ANA.GUIDE@EXAMPLE.TEST',
            MembershipRole::Guide,
            $property->id,
        );

        $this->assertSame($tenant->id, $membership->tenant_id);
        $this->assertSame($property->id, $membership->property_id);
        $this->assertSame(MembershipRole::Guide, $membership->role);
        $this->assertSame('Ana Guide', $membership->user->name);
        $this->assertSame('ana.guide@example.test', $membership->user->email);
        $this->assertNull($membership->user->email_verified_at);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'ana.guide@example.test']);
    }

    public function test_manager_cannot_manage_team_access(): void
    {
        $this->tenantEnvironment(MembershipRole::Manager, authenticate: false);

        $this->expectException(AuthorizationException::class);

        app(TeamMemberService::class)->invite('Guide', 'guide@example.test', MembershipRole::Guide, null);
    }

    public function test_property_scope_cannot_cross_tenants(): void
    {
        [$tenant, , , $membership] = $this->tenantEnvironment(authenticate: false);
        $otherTenant = Tenant::factory()->create();
        app(TenantContext::class)->set($otherTenant);
        $otherProperty = Property::factory()->create();
        app(TenantContext::class)->set($tenant, $membership);

        $this->expectException(ValidationException::class);

        app(TeamMemberService::class)->invite('Guide', 'guide@example.test', MembershipRole::Guide, $otherProperty->id);
    }

    public function test_last_active_administrator_cannot_be_demoted_or_deactivated(): void
    {
        [, , , $administrator] = $this->tenantEnvironment(authenticate: false);

        $this->expectException(DomainException::class);

        app(TeamMemberService::class)->update($administrator, MembershipRole::Manager, null, true);
    }

    public function test_administrator_can_change_access_after_another_active_administrator_exists(): void
    {
        [, , , $administrator] = $this->tenantEnvironment(authenticate: false);
        $secondAdministrator = Membership::factory()->create(['role' => MembershipRole::Administrator]);

        $updated = app(TeamMemberService::class)->update($administrator, MembershipRole::Manager, null, true);

        $this->assertSame(MembershipRole::Manager, $updated->role);
        $this->assertTrue($secondAdministrator->fresh()->is_active);
    }
}
