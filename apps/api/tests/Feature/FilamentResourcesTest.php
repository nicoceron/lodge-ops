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
use App\Filament\Resources\Guests\Pages\ViewGuest;
use App\Filament\Resources\Guests\RelationManagers\StaysRelationManager;
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
use App\Filament\Resources\ResourceBlocks\ResourceBlockResource;
use App\Filament\Resources\Resources\ResourceResource;
use App\Filament\Resources\RetailSales\RetailSaleResource;
use App\Filament\Resources\ServiceOccurrences\ServiceOccurrenceResource;
use App\Filament\Resources\StockLocations\StockLocationResource;
use App\Filament\Resources\TeamMembers\TeamMemberResource;
use App\Filament\Support\InnPresentation;
use App\Models\Guest;
use App\Models\Program;
use App\Models\Property;
use App\Models\Proposal;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\StockLocation;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    public function test_program_and_property_edit_pages_render_under_strict_authorization(): void
    {
        [$tenant, $property, $user] = $this->tenantEnvironment(authenticate: false);
        $program = Program::query()->create([
            'property_id' => $property->id,
            'name' => 'Editable program',
            'default_duration_minutes' => 60,
            'capacity' => 4,
            'currency' => 'USD',
            'price_minor' => 10_000,
            'requires_accommodation' => false,
            'is_active' => true,
        ]);
        $this->actingAs($user);

        $this->get(ProgramResource::getUrl('edit', ['tenant' => $tenant, 'record' => $program]))
            ->assertOk();
        $this->get(PropertyResource::getUrl('edit', ['tenant' => $tenant, 'record' => $property]))
            ->assertOk();
    }

    public function test_proposal_view_page_renders_the_pricing_snapshot(): void
    {
        [$tenant, $property, $user] = $this->tenantEnvironment(authenticate: false);
        $proposal = Proposal::query()->create([
            'reference' => 'Q-FILAMENT-VIEW',
            'property_id' => $property->id,
            'currency' => 'USD',
            'total_minor' => 125_000,
            'snapshot' => [
                'title' => 'Regression proposal',
                'subtotal_minor' => 125_000,
            ],
        ]);
        $this->actingAs($user);

        $this->get(ProposalResource::getUrl('view', ['tenant' => $tenant, 'record' => $proposal]))
            ->assertOk()
            ->assertSee('Regression proposal');
    }

    public function test_reservation_view_renders_the_operational_hub_under_strict_authorization(): void
    {
        [$tenant, $property, $user] = $this->tenantEnvironment(authenticate: false);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'confirmation_number' => 'RSV-HUB-VIEW',
        ]);
        $this->actingAs($user);

        $this->get(ReservationResource::getUrl('view', ['tenant' => $tenant, 'record' => $reservation]))
            ->assertOk()
            ->assertSee('RSV-HUB-VIEW')
            ->assertSee('Communications')
            ->assertSee('Documents');
    }

    public function test_guest_stay_history_includes_primary_and_companion_reservations(): void
    {
        [$tenant, $property, $user] = $this->tenantEnvironment(authenticate: false);
        $guest = Guest::factory()->create();
        $primaryStay = Reservation::factory()->create([
            'property_id' => $property->id,
            'primary_guest_id' => $guest->id,
            'confirmation_number' => 'RSV-PRIMARY-STAY',
        ]);
        $companionStay = Reservation::factory()->create([
            'property_id' => $property->id,
            'confirmation_number' => 'RSV-COMPANION-STAY',
        ]);
        $companionStay->guests()->attach($guest->id, [
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'role' => 'companion',
        ]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($tenant, isQuiet: true);

        Livewire::test(StaysRelationManager::class, [
            'ownerRecord' => $guest,
            'pageClass' => ViewGuest::class,
        ])->assertCanSeeTableRecords([$primaryStay, $companionStay]);
    }

    public function test_capabilities_control_each_operational_area(): void
    {
        [, , $operationsUser] = $this->tenantEnvironment(MembershipRole::Operations, authenticate: false);
        $this->actingAs($operationsUser);

        $this->assertTrue(OperationalTaskResource::canCreate());
        $this->assertTrue(ReservationResource::canCreate());
        $this->assertTrue(ResourceBlockResource::canCreate());
        $this->assertTrue(ServiceOccurrenceResource::canCreate());
        $this->assertTrue(ResourceResource::canViewAny());
        $this->assertFalse(ResourceResource::canCreate());
        $this->assertFalse(PropertyResource::canCreate());
        $this->assertFalse(TeamMemberResource::canViewAny());
        $this->assertFalse(PaymentResource::canViewAny());
    }

    public function test_property_scoped_membership_cannot_browse_other_property_records(): void
    {
        [, $property, $user] = $this->tenantEnvironment(MembershipRole::Operations, authenticate: false);
        $ownReservation = Reservation::factory()->create(['property_id' => $property->id]);
        $otherProperty = Property::factory()->create();
        $otherReservation = Reservation::factory()->create(['property_id' => $otherProperty->id]);
        $this->actingAs($user);

        $this->assertSame([$ownReservation->id], ReservationResource::getEloquentQuery()->pluck('id')->all());
        $this->assertFalse(ReservationResource::canView($otherReservation));
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
        $this->assertFalse(OperationalTaskResource::canCreate());
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

    public function test_reservation_composer_uses_server_pricing_and_creates_a_hold(): void
    {
        [$tenant, $property, $user, $membership] = $this->tenantEnvironment(authenticate: false);
        $guest = Guest::factory()->create();
        $category = $this->category($property, 'room');
        $room = Resource::factory()->create(['property_id' => $property->id, 'category_id' => $category->id, 'capacity' => 3]);
        $plan = RatePlan::query()->create(['property_id' => $property->id, 'name' => 'Filament rate', 'currency' => 'USD']);
        RateRule::query()->create(['rate_plan_id' => $plan->id, 'resource_category_id' => $category->id, 'amount_minor' => 10_000]);
        DB::table('rate_plans')->where('id', $plan->id)->update(['state' => 'published', 'published_at' => now()]);
        $this->actingAs($user);
        Filament::setCurrentPanel(filament()->getPanel('admin'));
        Filament::setTenant($tenant, isQuiet: true);
        app(TenantContext::class)->set($tenant, $membership);

        Livewire::test(CreateReservation::class)
            ->fillForm([
                'property_id' => $property->id,
                'primary_guest_id' => $guest->id,
                'resource_category_id' => $category->id,
                'resource_id' => $room->id,
                'rate_plan_id' => $plan->id,
                'starts_at' => now()->addMonth()->format('Y-m-d H:i:s'),
                'ends_at' => now()->addMonth()->addDays(2)->format('Y-m-d H:i:s'),
                'adults' => 2,
                'children' => 1,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('reservations', [
            'tenant_id' => $tenant->id,
            'status' => 'hold',
            'currency' => 'USD',
            'total_minor' => 20000,
        ]);
        $this->assertNotNull(Reservation::query()->latest()->value('booking_quote_id'));
    }

    public function test_property_scoped_membership_cannot_create_a_reservation_for_another_property(): void
    {
        [$tenant, , $user, $membership] = $this->tenantEnvironment(MembershipRole::Operations, authenticate: false);
        $otherProperty = Property::factory()->create();
        $guest = Guest::factory()->create();
        $category = $this->category($otherProperty, 'room');
        $room = Resource::factory()->create(['property_id' => $otherProperty->id, 'category_id' => $category->id]);
        $plan = RatePlan::query()->create(['property_id' => $otherProperty->id, 'name' => 'Other rate', 'currency' => 'USD']);
        RateRule::query()->create(['rate_plan_id' => $plan->id, 'resource_category_id' => $category->id, 'amount_minor' => 10_000]);
        $this->actingAs($user);
        Filament::setCurrentPanel(filament()->getPanel('admin'));
        Filament::setTenant($tenant, isQuiet: true);
        app(TenantContext::class)->set($tenant, $membership);

        Livewire::test(CreateReservation::class)
            ->fillForm([
                'property_id' => $otherProperty->id,
                'primary_guest_id' => $guest->id,
                'resource_category_id' => $category->id,
                'resource_id' => $room->id,
                'rate_plan_id' => $plan->id,
                'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'ends_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
                'adults' => 1,
                'children' => 0,
            ])
            ->call('create')
            ->assertHasFormErrors(['property_id']);

        $this->assertDatabaseMissing('reservations', ['property_id' => $otherProperty->id]);
    }

    public function test_automation_rule_form_exposes_the_runtime_milestone_triggers(): void
    {
        [$tenant, , $user] = $this->tenantEnvironment(authenticate: false);
        $this->actingAs($user);

        $this->get(AutomationRuleResource::getUrl('create', ['tenant' => $tenant]))
            ->assertOk()
            ->assertSee('Arrival approaching')
            ->assertSee('Deposit overdue')
            ->assertSee('Reservation checkout completed');
    }

    public function test_property_scoped_financial_and_retail_selectors_exclude_other_properties(): void
    {
        [$tenant, $property] = $this->tenantEnvironment(MembershipRole::Finance);
        $otherProperty = Property::factory()->for($tenant)->create();
        $reservation = Reservation::factory()->create(['property_id' => $property->id]);
        Reservation::factory()->create(['property_id' => $otherProperty->id]);
        $location = StockLocation::query()->create(['property_id' => $property->id, 'name' => 'Own stock', 'code' => 'OWN']);
        StockLocation::query()->create(['property_id' => $otherProperty->id, 'name' => 'Other stock', 'code' => 'OTHER']);

        $this->assertSame([$reservation->id], array_keys(InnPresentation::reservationOptions()));
        $this->assertSame([$location->id], array_keys(InnPresentation::stockLocationOptions()));
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
            ResourceBlockResource::class,
            ServiceOccurrenceResource::class,
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
            TeamMemberResource::class,
        ];
    }
}
