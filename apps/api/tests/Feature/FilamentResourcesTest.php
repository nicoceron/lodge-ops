<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Filament\Resources\AutomationRules\AutomationRuleResource;
use App\Filament\Resources\CatalogItems\CatalogItemResource;
use App\Filament\Resources\CommissionAccruals\CommissionAccrualResource;
use App\Filament\Resources\CommunicationSuppressions\CommunicationSuppressionResource;
use App\Filament\Resources\CostRecords\CostRecordResource;
use App\Filament\Resources\Deposits\DepositResource;
use App\Filament\Resources\DocumentTemplates\DocumentTemplateResource;
use App\Filament\Resources\ExchangeRates\ExchangeRateResource;
use App\Filament\Resources\FolioLines\FolioLineResource;
use App\Filament\Resources\GeneratedDocuments\GeneratedDocumentResource;
use App\Filament\Resources\Guests\GuestResource;
use App\Filament\Resources\Guests\Pages\CreateGuest;
use App\Filament\Resources\IntegrationConnections\IntegrationConnectionResource;
use App\Filament\Resources\MessageTemplates\MessageTemplateResource;
use App\Filament\Resources\OperationalTasks\OperationalTaskResource;
use App\Filament\Resources\Opportunities\OpportunityResource;
use App\Filament\Resources\Organizations\OrganizationResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Programs\ProgramResource;
use App\Filament\Resources\Properties\PropertyResource;
use App\Filament\Resources\Proposals\ProposalResource;
use App\Filament\Resources\ReportExports\ReportExportResource;
use App\Filament\Resources\Reservations\Pages\CreateReservation;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Filament\Resources\Resources\ResourceResource;
use App\Filament\Resources\RetailSales\RetailSaleResource;
use App\Filament\Resources\StockLocations\StockLocationResource;
use App\Models\Guest;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class FilamentResourcesTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        Filament::setTenant(null, isQuiet: true);
        Filament::setCurrentPanel(null);

        parent::tearDown();
    }

    public function test_owner_can_render_every_tenant_resource_index(): void
    {
        [$tenant, , $user] = $this->tenantEnvironment(authenticate: false);
        $this->actingAs($user);

        foreach ($this->resourceClasses() as $resource) {
            $this->get($resource::getUrl('index', ['tenant' => $tenant]))
                ->assertOk();
        }
    }

    public function test_resource_queries_and_record_routes_cannot_cross_tenants(): void
    {
        [$tenant, , $user, $membership] = $this->tenantEnvironment(authenticate: false);
        $firstGuest = Guest::factory()->create();

        $otherTenant = Tenant::factory()->create();
        app(TenantContext::class)->set($otherTenant);
        $otherGuest = Guest::factory()->create();

        app(TenantContext::class)->set($tenant, $membership);
        $this->actingAs($user);
        Filament::setCurrentPanel(filament()->getPanel('admin'));
        Filament::setTenant($tenant, isQuiet: true);

        $this->assertSame([$firstGuest->id], GuestResource::getEloquentQuery()->pluck('id')->all());
        $this->assertTrue(GuestResource::canView($firstGuest));
        $this->assertFalse(GuestResource::canView($otherGuest));

        $this->get(GuestResource::getUrl('view', [
            'tenant' => $tenant,
            'record' => $otherGuest,
        ]))->assertNotFound();
    }

    public function test_capabilities_control_each_operational_area(): void
    {
        [, , $operationsUser] = $this->tenantEnvironment(MembershipRole::Operations, authenticate: false);
        $this->actingAs($operationsUser);

        $this->assertTrue(OperationalTaskResource::canCreate());
        $this->assertTrue(ReservationResource::canCreate());
        $this->assertFalse(PropertyResource::canCreate());
        $this->assertFalse(PaymentResource::canViewAny());
    }

    public function test_finance_can_read_but_never_mutate_payment_records(): void
    {
        [, , $financeUser] = $this->tenantEnvironment(MembershipRole::Finance, authenticate: false);
        $this->actingAs($financeUser);

        $this->assertTrue(PaymentResource::canViewAny());
        $this->assertFalse(GuestResource::canViewAny());
        $this->assertFalse(ReservationResource::canViewAny());
        $this->assertFalse(PaymentResource::canCreate());
        $this->assertFalse(PaymentResource::canDeleteAny());
        $this->assertSame(['index', 'view'], array_keys(PaymentResource::getPages()));
    }

    public function test_kitchen_can_work_tasks_without_browsing_guest_profiles(): void
    {
        [, , $kitchenUser] = $this->tenantEnvironment(MembershipRole::Kitchen, authenticate: false);
        $this->actingAs($kitchenUser);

        $this->assertTrue(OperationalTaskResource::canViewAny());
        $this->assertTrue(OperationalTaskResource::canCreate());
        $this->assertFalse(GuestResource::canViewAny());
    }

    public function test_guest_create_form_derives_tenant_from_the_active_workspace(): void
    {
        [$tenant, , $user, $membership] = $this->tenantEnvironment(authenticate: false);
        $this->actingAs($user);
        Filament::setCurrentPanel(filament()->getPanel('admin'));
        Filament::setTenant($tenant, isQuiet: true);
        app(TenantContext::class)->set($tenant, $membership);

        Livewire::test(CreateGuest::class)
            ->fillForm([
                'first_name' => 'María',
                'last_name' => 'Torres',
                'email' => 'maria@example.test',
                'language' => 'es',
                'marketing_consent' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('guests', [
            'tenant_id' => $tenant->id,
            'email' => 'maria@example.test',
        ]);
    }

    public function test_reservation_form_calculates_integer_total_and_forces_draft_workflow(): void
    {
        [$tenant, $property, $user, $membership] = $this->tenantEnvironment(authenticate: false);
        $guest = Guest::factory()->create();
        $this->actingAs($user);
        Filament::setCurrentPanel(filament()->getPanel('admin'));
        Filament::setTenant($tenant, isQuiet: true);
        app(TenantContext::class)->set($tenant, $membership);

        Livewire::test(CreateReservation::class)
            ->fillForm([
                'property_id' => $property->id,
                'primary_guest_id' => $guest->id,
                'confirmation_number' => 'RSV-FILAMENT-001',
                'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'ends_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
                'adults' => 2,
                'children' => 1,
                'currency' => 'usd',
                'subtotal_minor' => 10000,
                'tax_minor' => 1900,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('reservations', [
            'tenant_id' => $tenant->id,
            'confirmation_number' => 'RSV-FILAMENT-001',
            'status' => 'draft',
            'currency' => 'USD',
            'total_minor' => 11900,
        ]);
    }

    /** @return array<class-string> */
    private function resourceClasses(): array
    {
        return [
            PropertyResource::class,
            ResourceResource::class,
            ProgramResource::class,
            GuestResource::class,
            ReservationResource::class,
            OperationalTaskResource::class,
            PaymentResource::class,
            ProposalResource::class,
            DepositResource::class,
            FolioLineResource::class,
            AutomationRuleResource::class,
            OrganizationResource::class,
            OpportunityResource::class,
            CatalogItemResource::class,
            StockLocationResource::class,
            RetailSaleResource::class,
            CostRecordResource::class,
            CommissionAccrualResource::class,
            ExchangeRateResource::class,
            DocumentTemplateResource::class,
            GeneratedDocumentResource::class,
            MessageTemplateResource::class,
            CommunicationSuppressionResource::class,
            IntegrationConnectionResource::class,
            ReportExportResource::class,
        ];
    }
}
