<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Filament\Pages\FinanceDashboard;
use App\Filament\Pages\KitchenDashboard;
use App\Filament\Pages\OperationsBoard;
use App\Filament\Resources\CostRecords\CostRecordResource;
use App\Filament\Resources\OperationalTasks\OperationalTaskResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Filament\Widgets\LodgeCommandCenter;
use App\Filament\Widgets\LodgeReadinessOverview;
use App\Models\OperationalTask;
use App\Models\Payment;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class RoleSemanticsTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        Filament::setTenant(null, isQuiet: true);
        Filament::setCurrentPanel(null);

        parent::tearDown();
    }

    public function test_administrator_has_full_application_access(): void
    {
        [$tenant, , $user] = $this->tenantEnvironment(MembershipRole::Administrator, authenticate: false);
        $this->actingAs($user);
        Filament::setCurrentPanel(filament()->getPanel('admin'));
        Filament::setTenant($tenant, isQuiet: true);

        $this->assertTrue(ReservationResource::canCreate());
        $this->assertTrue(OperationalTaskResource::canCreate());
        $this->assertTrue(CostRecordResource::canCreate());
        $this->assertTrue(FinanceDashboard::canAccess());
        $this->assertTrue(OperationsBoard::canAccess());
    }

    public function test_owner_has_read_only_finance_access_and_no_operational_access(): void
    {
        [$tenant, , $user] = $this->tenantEnvironment(MembershipRole::Owner, authenticate: false);
        $this->actingAs($user);

        $this->assertTrue(FinanceDashboard::canAccess());
        $this->assertTrue(PaymentResource::canViewAny());
        $this->assertTrue(CostRecordResource::canViewAny());
        $this->assertFalse(CostRecordResource::canCreate());
        $this->assertFalse(ReservationResource::canViewAny());
        $this->assertFalse(OperationalTaskResource::canViewAny());
        $this->assertFalse(OperationsBoard::canAccess());
        $this->assertFalse(KitchenDashboard::canAccess());
        $this->assertFalse(LodgeCommandCenter::canView());
        $this->assertFalse(LodgeReadinessOverview::canView());

        $payment = new Payment;
        $payment->tenant_id = app(TenantContext::class)->id();
        $this->assertTrue($user->can('viewFinance', Payment::class));
        $this->assertFalse($user->can('create', Payment::class));

        Sanctum::actingAs($user);
        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/finance')->assertOk();
        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/operations')->assertForbidden();
        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/reservations')->assertForbidden();
    }

    public function test_kitchen_only_sees_and_updates_kitchen_tasks_without_creating_operations_work(): void
    {
        [, $property, $user] = $this->tenantEnvironment(MembershipRole::Kitchen, authenticate: false);
        $this->actingAs($user);
        $kitchenTask = OperationalTask::query()->create([
            'property_id' => $property->id,
            'title' => 'Prepare allergy-safe meal',
            'status' => 'todo',
            'priority' => 'high',
            'metadata' => ['assignee_role' => 'kitchen'],
        ]);
        $housekeepingTask = OperationalTask::query()->create([
            'property_id' => $property->id,
            'title' => 'Turn over room',
            'status' => 'todo',
            'priority' => 'normal',
            'metadata' => ['assignee_role' => 'housekeeping'],
        ]);

        $ids = OperationalTaskResource::getEloquentQuery()->pluck('id')->all();

        $this->assertContains($kitchenTask->id, $ids);
        $this->assertNotContains($housekeepingTask->id, $ids);
        $this->assertTrue(OperationalTaskResource::canView($kitchenTask));
        $this->assertFalse(OperationalTaskResource::canView($housekeepingTask));
        $this->assertTrue(OperationalTaskResource::canEdit($kitchenTask));
        $this->assertFalse(OperationalTaskResource::canCreate());
        $this->assertTrue(KitchenDashboard::canAccess());
        $this->assertFalse(OperationsBoard::canAccess());
        $this->assertFalse(ReservationResource::canViewAny());
    }
}
