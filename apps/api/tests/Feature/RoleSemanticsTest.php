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
use App\Filament\Resources\ServiceOccurrences\Pages\ManageServiceOccurrences;
use App\Filament\Resources\ServiceOccurrences\ServiceOccurrenceResource;
use App\Filament\Resources\TenantResource;
use App\Filament\Widgets\LodgeCommandCenter;
use App\Filament\Widgets\LodgeReadinessOverview;
use App\Models\AutomationRule;
use App\Models\CatalogItem;
use App\Models\CommissionAccrual;
use App\Models\CommunicationSuppression;
use App\Models\CostRecord;
use App\Models\CrmActivity;
use App\Models\Deposit;
use App\Models\DocumentTemplate;
use App\Models\ExchangeRate;
use App\Models\FolioLine;
use App\Models\GeneratedDocument;
use App\Models\Guest;
use App\Models\IntegrationConnection;
use App\Models\Membership;
use App\Models\MessageTemplate;
use App\Models\MessageTemplateVersion;
use App\Models\OperationalTask;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Program;
use App\Models\Property;
use App\Models\Proposal;
use App\Models\ReportExport;
use App\Models\Reservation;
use App\Models\ResourceBlock;
use App\Models\RetailSale;
use App\Models\ServiceOccurrence;
use App\Models\StockLocation;
use App\Models\Survey;
use App\Models\TenantModel;
use App\Services\MessageTemplateService;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
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

    public function test_guide_cannot_cancel_a_service_occurrence(): void
    {
        [$tenant, $property, $user] = $this->tenantEnvironment(MembershipRole::Guide, authenticate: false);
        $program = Program::query()->create([
            'property_id' => $property->id,
            'name' => 'Morning drive',
            'currency' => 'USD',
        ]);
        $occurrence = ServiceOccurrence::query()->create([
            'program_id' => $program->id,
            'property_id' => $property->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHours(2),
        ]);
        $this->actingAs($user);
        Filament::setCurrentPanel(filament()->getPanel('admin'));
        Filament::setTenant($tenant, isQuiet: true);

        Livewire::test(ManageServiceOccurrences::class)
            ->assertTableActionHidden('cancel', $occurrence);

        $this->assertFalse($occurrence->refresh()->is_cancelled);
    }

    public function test_filament_resources_honor_laravel_policy_denials(): void
    {
        [, , $user] = $this->tenantEnvironment(MembershipRole::Administrator, authenticate: false);
        $this->actingAs($user);
        Gate::policy(ServiceOccurrence::class, DenyServiceOccurrencePolicy::class);

        $this->assertFalse(ServiceOccurrenceResource::canViewAny());
    }

    public function test_filament_resources_fail_closed_when_a_policy_or_ability_is_missing(): void
    {
        [, , $user] = $this->tenantEnvironment(MembershipRole::Administrator, authenticate: false);
        $this->actingAs($user);

        $this->assertFalse(PolicylessTenantRecordResource::canViewAny());

        Gate::policy(ServiceOccurrence::class, ViewOnlyServiceOccurrencePolicy::class);
        $this->assertTrue(ServiceOccurrenceResource::canViewAny());
        $this->assertFalse(ServiceOccurrenceResource::canCreate());
    }

    public function test_every_tenant_resource_model_has_explicit_view_policy_coverage(): void
    {
        foreach ([
            AutomationRule::class,
            CatalogItem::class,
            CommissionAccrual::class,
            CommunicationSuppression::class,
            CostRecord::class,
            Deposit::class,
            DocumentTemplate::class,
            ExchangeRate::class,
            FolioLine::class,
            GeneratedDocument::class,
            Guest::class,
            IntegrationConnection::class,
            Membership::class,
            MessageTemplate::class,
            Opportunity::class,
            OperationalTask::class,
            Organization::class,
            Payment::class,
            Program::class,
            Property::class,
            Proposal::class,
            ReportExport::class,
            Reservation::class,
            \App\Models\Resource::class,
            ResourceBlock::class,
            RetailSale::class,
            ServiceOccurrence::class,
            StockLocation::class,
            Survey::class,
        ] as $model) {
            $policy = Gate::getPolicyFor($model);
            $this->assertNotNull($policy, "Missing policy for {$model}");

            foreach (['viewAny', 'view'] as $ability) {
                $this->assertTrue(method_exists($policy, $ability), "Missing {$ability} policy ability for {$model}");
            }
        }
    }

    public function test_message_template_versions_can_be_viewed_by_authorized_configuration_managers(): void
    {
        [, , $user] = $this->tenantEnvironment(MembershipRole::Administrator, authenticate: false);
        $this->actingAs($user);
        $template = MessageTemplate::query()->create([
            'name' => 'Arrival',
            'key' => 'arrival-review',
            'channel' => 'email',
            'is_active' => true,
        ]);
        $version = app(MessageTemplateService::class)->createVersion($template, 'en', 'Welcome', 'Hello traveler');

        $this->assertTrue($user->can('view', $version));
    }

    public function test_custom_workflow_models_have_laravel_mutation_policies(): void
    {
        [, , $user] = $this->tenantEnvironment(MembershipRole::Administrator, authenticate: false);
        $this->actingAs($user);

        foreach ([
            CommissionAccrual::class,
            CrmActivity::class,
            MessageTemplate::class,
            MessageTemplateVersion::class,
            Opportunity::class,
        ] as $model) {
            $this->assertTrue($user->can('create', $model), "Missing create policy for {$model}");
        }
    }
}

class DenyServiceOccurrencePolicy
{
    public function viewAny(): bool
    {
        return false;
    }
}

class ViewOnlyServiceOccurrencePolicy
{
    public function viewAny(): bool
    {
        return true;
    }
}

class PolicylessTenantRecord extends TenantModel {}

class PolicylessTenantRecordResource extends TenantResource
{
    protected static ?string $model = PolicylessTenantRecord::class;
}
