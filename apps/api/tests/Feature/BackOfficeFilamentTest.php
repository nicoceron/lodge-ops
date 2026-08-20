<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Filament\Resources\CatalogItems\CatalogItemResource;
use App\Filament\Resources\CommissionAccruals\CommissionAccrualResource;
use App\Filament\Resources\CommunicationSuppressions\CommunicationSuppressionResource;
use App\Filament\Resources\CommunicationSuppressions\Pages\ManageCommunicationSuppressions;
use App\Filament\Resources\CostRecords\CostRecordResource;
use App\Filament\Resources\DocumentTemplates\DocumentTemplateResource;
use App\Filament\Resources\DocumentTemplates\Pages\CreateDocumentTemplate;
use App\Filament\Resources\ExchangeRates\ExchangeRateResource;
use App\Filament\Resources\ExchangeRates\Pages\ManageExchangeRates;
use App\Filament\Resources\GeneratedDocuments\GeneratedDocumentResource;
use App\Filament\Resources\IntegrationConnections\IntegrationConnectionResource;
use App\Filament\Resources\IntegrationConnections\Pages\ManageIntegrationConnections;
use App\Filament\Resources\IntegrationMappings\IntegrationMappingResource;
use App\Filament\Resources\IntegrationMappings\Pages\ManageIntegrationMappings;
use App\Filament\Resources\MessageTemplates\MessageTemplateResource;
use App\Filament\Resources\Opportunities\OpportunityResource;
use App\Filament\Resources\Organizations\OrganizationResource;
use App\Filament\Resources\ReportExports\ReportExportResource;
use App\Filament\Resources\RetailSales\Pages\CreateRetailSale;
use App\Filament\Resources\RetailSales\RetailSaleResource;
use App\Filament\Resources\StockLocations\StockLocationResource;
use App\Models\CatalogItem;
use App\Models\CommunicationSuppression;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\IntegrationConnection;
use App\Models\IntegrationMapping;
use App\Models\IntegrationReconciliation;
use App\Models\MessageTemplate;
use App\Models\Opportunity;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\RetailSale;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Services\IntegrationConnectionService;
use App\Services\MessageTemplateService;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use LogicException;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class BackOfficeFilamentTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        Filament::setTenant(null, isQuiet: true);
        Filament::setCurrentPanel(null);

        parent::tearDown();
    }

    public function test_back_office_navigation_is_partitioned_by_domain_capability(): void
    {
        [, , $salesUser] = $this->tenantEnvironment(MembershipRole::Sales, authenticate: false);
        $this->actingAs($salesUser);
        $this->assertTrue(OrganizationResource::canViewAny());
        $this->assertTrue(OpportunityResource::canCreate());
        $this->assertFalse(RetailSaleResource::canViewAny());
        $this->assertFalse(CostRecordResource::canViewAny());
        $this->assertFalse(DocumentTemplateResource::canViewAny());

        [, , $operationsUser] = $this->tenantEnvironment(MembershipRole::Operations, authenticate: false);
        $this->actingAs($operationsUser);
        $this->assertTrue(CatalogItemResource::canViewAny());
        $this->assertTrue(StockLocationResource::canCreate());
        $this->assertTrue(RetailSaleResource::canCreate());
        $this->assertFalse(OpportunityResource::canViewAny());
        $this->assertFalse(IntegrationConnectionResource::canViewAny());

        [, , $financeUser] = $this->tenantEnvironment(MembershipRole::Finance, authenticate: false);
        $this->actingAs($financeUser);
        $this->assertTrue(CostRecordResource::canCreate());
        $this->assertTrue(CommissionAccrualResource::canViewAny());
        $this->assertTrue(ExchangeRateResource::canCreate());
        $this->assertTrue(ReportExportResource::canViewAny());
        $this->assertTrue(GeneratedDocumentResource::canViewAny());

        [, , $managerUser] = $this->tenantEnvironment(MembershipRole::Manager, authenticate: false);
        $this->actingAs($managerUser);
        $this->assertTrue(DocumentTemplateResource::canCreate());
        $this->assertTrue(GeneratedDocumentResource::canViewAny());
        $this->assertTrue(MessageTemplateResource::canCreate());
        $this->assertTrue(CommunicationSuppressionResource::canCreate());
        $this->assertTrue(IntegrationConnectionResource::canCreate());
    }

    public function test_retail_sale_livewire_page_posts_stock_and_folio_atomically(): void
    {
        [$tenant, $property, $user, $membership] = $this->tenantEnvironment(authenticate: false);
        $this->prepareFilament($tenant, $membership, $user);
        $location = StockLocation::query()->create(['property_id' => $property->id, 'name' => 'Main shop', 'code' => 'SHOP']);
        $item = CatalogItem::query()->create([
            'sku' => 'MUG-OPS',
            'name' => 'Lodge mug',
            'type' => 'retail',
            'currency' => 'USD',
            'price_minor' => 1800,
            'cost_minor' => 700,
            'track_stock' => true,
            'is_active' => true,
        ]);
        StockMovement::query()->create([
            'catalog_item_id' => $item->id,
            'stock_location_id' => $location->id,
            'type' => 'receipt',
            'quantity' => '5.000',
            'unit_cost_minor' => 700,
            'reference' => 'FILAMENT-RECEIPT-1',
            'occurred_at' => now(),
        ]);
        $reservation = Reservation::factory()->create(['property_id' => $property->id, 'currency' => 'USD']);

        Livewire::test(CreateRetailSale::class)
            ->fillForm([
                'stock_location_id' => $location->id,
                'reservation_id' => $reservation->id,
                'reference' => 'FILAMENT-SALE-1',
                'tax_minor' => 200,
                'lines' => [['catalog_item_id' => $item->id, 'quantity_milli' => 2000]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('retail_sales', ['reference' => 'FILAMENT-SALE-1', 'subtotal_minor' => 3600, 'total_minor' => 3800]);
        $this->assertDatabaseHas('stock_movements', ['reference' => "FILAMENT-SALE-1:{$item->id}", 'quantity' => '-2.000']);
        $this->assertDatabaseHas('folio_lines', ['reservation_id' => $reservation->id, 'amount_minor' => 3600]);

        $sale = RetailSale::query()->where('reference', 'FILAMENT-SALE-1')->firstOrFail();
        $this->assertThrows(fn () => $sale->update(['total_minor' => 1]), LogicException::class);
        $this->assertThrows(fn () => $sale->delete(), LogicException::class);
        $postedMovement = StockMovement::query()->where('reference', "FILAMENT-SALE-1:{$item->id}")->firstOrFail();
        $this->assertThrows(fn () => $postedMovement->update(['quantity' => '-1.000']), LogicException::class);
        $this->assertThrows(fn () => $postedMovement->delete(), LogicException::class);
        $saleLine = $sale->lines()->firstOrFail();
        $this->assertThrows(fn () => $saleLine->update(['amount_minor' => 1]), LogicException::class);
        $this->assertThrows(fn () => $saleLine->delete(), LogicException::class);
    }

    public function test_document_versions_and_recipient_hashing_use_domain_safe_livewire_actions(): void
    {
        [$tenant, , $user, $membership] = $this->tenantEnvironment(authenticate: false);
        $this->prepareFilament($tenant, $membership, $user);

        Livewire::test(CreateDocumentTemplate::class)
            ->fillForm(['name' => 'Guest waiver', 'kind' => 'guest-waiver', 'definition' => ['title' => 'Guest waiver']])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('document_templates', ['kind' => 'guest-waiver', 'version' => 1, 'is_active' => true]);

        Livewire::test(ManageCommunicationSuppressions::class)
            ->callAction('create', [
                'channel' => 'email',
                'recipient' => ' Traveler@Example.com ',
                'reason' => 'unsubscribe',
            ])
            ->assertHasNoActionErrors();

        $suppression = CommunicationSuppression::query()->firstOrFail();
        $this->assertSame(hash('sha256', 'traveler@example.com'), $suppression->recipient_hash);
        $this->assertSame(1, DocumentTemplate::query()->where('kind', 'guest-waiver')->count());
    }

    public function test_grouped_relation_views_render_without_exposing_separate_audit_mutation_screens(): void
    {
        [$tenant, $property, $user, $membership] = $this->tenantEnvironment(authenticate: false);
        $this->prepareFilament($tenant, $membership, $user);
        $opportunity = Opportunity::query()->create([
            'property_id' => $property->id,
            'owner_id' => $user->id,
            'title' => 'Group retreat',
            'stage' => 'inquiry',
            'currency' => 'USD',
            'value_minor' => 100000,
        ]);
        $location = StockLocation::query()->create(['property_id' => $property->id, 'name' => 'Main shop', 'code' => 'SHOP']);
        $sale = RetailSale::query()->create([
            'stock_location_id' => $location->id,
            'reference' => 'READONLY-SALE',
            'status' => 'posted',
            'currency' => 'USD',
            'subtotal_minor' => 0,
            'tax_minor' => 0,
            'total_minor' => 0,
            'posted_at' => now(),
        ]);
        $documentTemplate = DocumentTemplate::query()->create(['name' => 'Waiver', 'kind' => 'waiver', 'version' => 1, 'definition' => [], 'is_active' => true]);
        $messageTemplate = MessageTemplate::query()->create(['name' => 'Arrival', 'key' => 'arrival', 'channel' => 'email', 'is_active' => true]);

        foreach ([
            OpportunityResource::getUrl('view', ['tenant' => $tenant, 'record' => $opportunity]),
            StockLocationResource::getUrl('view', ['tenant' => $tenant, 'record' => $location]),
            RetailSaleResource::getUrl('view', ['tenant' => $tenant, 'record' => $sale]),
            DocumentTemplateResource::getUrl('view', ['tenant' => $tenant, 'record' => $documentTemplate]),
            MessageTemplateResource::getUrl('view', ['tenant' => $tenant, 'record' => $messageTemplate]),
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_exchange_rate_and_integration_modals_delegate_to_safe_services(): void
    {
        [$tenant, , $user, $membership] = $this->tenantEnvironment(authenticate: false);
        $this->prepareFilament($tenant, $membership, $user);

        Livewire::test(ManageExchangeRates::class)
            ->callAction('create', [
                'base_currency' => 'usd',
                'quote_currency' => 'cop',
                'rate' => '4012.3456789012',
                'source' => 'central-bank',
                'effective_at' => now()->toDateTimeString(),
            ])
            ->assertHasNoActionErrors();
        $this->assertDatabaseHas('exchange_rates', ['base_currency' => 'USD', 'quote_currency' => 'COP', 'rate' => '4012.3456789012']);

        Livewire::test(ManageIntegrationConnections::class)
            ->callAction('create', [
                'name' => 'Operations calendar',
                'type' => 'calendar',
                'configuration' => ['calendar_id' => 'ops'],
                'secret_reference' => 'vault://tenant/calendar',
            ])
            ->assertHasNoActionErrors();

        $connection = IntegrationConnection::query()->firstOrFail();
        $this->assertSame('configured', $connection->status);
        $this->assertSame(['calendar_id' => 'ops'], $connection->configuration);

        $webhook = app(IntegrationConnectionService::class)->configure(
            'Rendered webhook', 'webhook', ['webhook_signing_secret_reference' => 'vault://tenant/render-secret'],
            'env:RENDERED_CONNECTION_SECRET', $membership->property_id, 'contract_fake', 'webhooks', 'render-account',
            'sandbox', ['webhook.inbound'],
        );
        $this->prepareFilament($tenant, $membership, $user);
        $this->assertSame($membership->property_id, $webhook->property_id);
        $this->assertTrue(IntegrationConnectionResource::getEloquentQuery()->whereKey($webhook->id)->exists());
        Livewire::test(ManageIntegrationConnections::class)
            ->assertCanSeeTableRecords([$webhook])
            ->assertSee('[configured]')
            ->assertDontSee('vault://tenant/render-secret')
            ->assertDontSee('env:RENDERED_CONNECTION_SECRET');
    }

    public function test_mapping_create_modal_versions_through_service_and_enforces_role_and_property_scope(): void
    {
        [$tenant, $property, $user, $membership] = $this->tenantEnvironment(MembershipRole::Administrator, authenticate: false);
        $this->prepareFilament($tenant, $membership, $user);
        $connection = app(IntegrationConnectionService::class)->configure(
            'Mapping connection', 'webhook', [], 'env:MAPPING_SECRET', $property->id,
            'contract_fake', 'reservations', 'mapping-account', 'sandbox', ['reservations.import'],
        );
        $base = [
            'integration_connection_id' => $connection->id, 'property_id' => $property->id,
            'capability' => 'reservations.import', 'direction' => 'inbound',
            'local_entity_type' => 'reservation', 'local_key' => 'local-1',
            'external_entity_type' => 'booking', 'external_key' => 'external-1',
            'transform_version' => 1, 'safe_facts' => ['status' => 'confirmed'],
        ];
        Livewire::test(ManageIntegrationMappings::class)->callAction('create', $base)->assertHasNoActionErrors();
        Livewire::test(ManageIntegrationMappings::class)->callAction('create', [
            ...$base, 'local_key' => 'local-2', 'transform_version' => 2,
        ])->assertHasNoActionErrors();
        $this->assertSame(2, IntegrationMapping::query()->count());
        $this->assertNotNull(IntegrationMapping::query()->oldest('valid_from')->firstOrFail()->valid_until);
        $this->assertSame(1, IntegrationReconciliation::query()->where('kind', 'mapping_drift')->count());

        $otherProperty = Property::factory()->create();
        Livewire::test(ManageIntegrationMappings::class)->callAction('create', [
            ...$base, 'property_id' => $otherProperty->id, 'external_key' => 'outside-scope',
        ])->assertActionHalted();

        [, , $operations] = $this->tenantEnvironment(MembershipRole::Operations, authenticate: false);
        $this->actingAs($operations);
        $this->assertFalse(IntegrationMappingResource::canCreate());
    }

    public function test_generated_documents_and_published_messages_are_immutable_audit_records(): void
    {
        [, , $user] = $this->tenantEnvironment();
        $document = GeneratedDocument::query()->create([
            'kind' => 'waiver',
            'status' => 'generated',
            'storage_path' => 'tenants/test/documents/waiver.pdf',
            'checksum' => str_repeat('a', 64),
            'storage_disk' => 'local',
            'file_name' => 'waiver.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 0,
            'source_checksum' => str_repeat('a', 64),
            'renderer' => 'legacy-test',
            'renderer_version' => 'legacy',
            'template_version' => 0,
            'locale' => 'en',
            'generated_at' => now(),
            'metadata' => ['created_by' => $user->id],
        ]);
        $this->assertThrows(fn () => $document->update(['status' => 'tampered']), LogicException::class);
        $this->assertThrows(fn () => $document->delete(), LogicException::class);

        $template = MessageTemplate::query()->create(['name' => 'Arrival', 'key' => 'arrival', 'channel' => 'email', 'is_active' => true]);
        $version = app(MessageTemplateService::class)->createVersion($template, 'en', 'Welcome', 'Hello traveler');
        app(MessageTemplateService::class)->publish($version);
        $this->assertThrows(fn () => $version->update(['body' => 'Tampered']), LogicException::class);
        $this->assertThrows(fn () => $version->delete(), LogicException::class);
    }

    private function prepareFilament(object $tenant, object $membership, object $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(filament()->getPanel('admin'));
        Filament::setTenant($tenant, isQuiet: true);
        app(TenantContext::class)->set($tenant, $membership);
    }
}
