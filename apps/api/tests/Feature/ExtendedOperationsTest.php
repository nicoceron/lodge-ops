<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\PaymentStatus;
use App\Models\CatalogItem;
use App\Models\CommunicationSuppression;
use App\Models\CostRecord;
use App\Models\Guest;
use App\Models\IntegrationConnection;
use App\Models\MessageTemplate;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Services\CommissionService;
use App\Services\DocumentService;
use App\Services\ExchangeRateService;
use App\Services\FinancialReportingService;
use App\Services\GuestMergeService;
use App\Services\IntegrationConnectionService;
use App\Services\MessageTemplateService;
use App\Services\OpportunityService;
use App\Services\RetailPostingService;
use App\Services\SafeCsvExporter;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class ExtendedOperationsTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_retail_sale_posts_stock_and_an_append_only_reservation_charge_idempotently(): void
    {
        [, $property] = $this->tenantEnvironment();
        $location = StockLocation::query()->create(['property_id' => $property->id, 'name' => 'Lodge shop', 'code' => 'SHOP']);
        $item = CatalogItem::query()->create([
            'sku' => 'FLY-001', 'name' => 'Hand-tied fly', 'type' => 'retail', 'currency' => 'USD',
            'price_minor' => 1250, 'cost_minor' => 400, 'track_stock' => true, 'is_active' => true,
        ]);
        StockMovement::query()->create([
            'catalog_item_id' => $item->id, 'stock_location_id' => $location->id, 'type' => 'receipt',
            'quantity' => '5.000', 'unit_cost_minor' => 400, 'reference' => 'RECEIPT-1', 'occurred_at' => now(),
        ]);
        $reservation = Reservation::factory()->create(['property_id' => $property->id, 'currency' => 'USD']);

        $service = app(RetailPostingService::class);
        $sale = $service->post($location, 'SALE-1', [['catalog_item_id' => $item->id, 'quantity_milli' => 1500]], $reservation);
        $replayed = $service->post($location, 'SALE-1', [['catalog_item_id' => $item->id, 'quantity_milli' => 1500]], $reservation);

        $this->assertSame($sale->id, $replayed->id);
        $this->assertSame(1875, $sale->subtotal_minor);
        $this->assertDatabaseCount('retail_sales', 1);
        $this->assertDatabaseCount('retail_sale_lines', 1);
        $this->assertDatabaseCount('folio_lines', 1);
        $this->assertDatabaseHas('stock_movements', ['reference' => "SALE-1:{$item->id}", 'quantity' => '-1.500']);
    }

    public function test_retail_sale_rejects_insufficient_stock_without_partial_posting(): void
    {
        [, $property] = $this->tenantEnvironment();
        $location = StockLocation::query()->create(['property_id' => $property->id, 'name' => 'Lodge shop', 'code' => 'SHOP']);
        $item = CatalogItem::query()->create([
            'sku' => 'HAT-001', 'name' => 'Cap', 'currency' => 'USD', 'price_minor' => 2500,
            'track_stock' => true, 'is_active' => true,
        ]);

        try {
            app(RetailPostingService::class)->post($location, 'SALE-NO-STOCK', [['catalog_item_id' => $item->id, 'quantity_milli' => 1000]]);
            $this->fail('An out-of-stock sale must fail.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Insufficient stock', $exception->getMessage());
        }

        $this->assertDatabaseCount('retail_sales', 0);
        $this->assertDatabaseCount('retail_sale_lines', 0);
    }

    public function test_financial_summary_reconciles_bookings_cash_costs_and_commissions_in_one_currency(): void
    {
        [, $property] = $this->tenantEnvironment();
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'currency' => 'USD',
            'total_minor' => 100_000,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(3),
        ]);
        Payment::query()->create([
            'reservation_id' => $reservation->id, 'status' => PaymentStatus::Succeeded,
            'method' => 'bank_transfer', 'currency' => 'USD', 'amount_minor' => 40_000, 'processed_at' => now(),
        ]);
        CostRecord::query()->create([
            'reservation_id' => $reservation->id, 'kind' => 'actual', 'category' => 'guide',
            'description' => 'Guide day', 'currency' => 'USD', 'amount_minor' => 10_000, 'occurred_at' => now()->addDay(),
        ]);
        app(CommissionService::class)->accrue($reservation, 'agency', 'Southbound Travel', 1000);

        $summary = app(FinancialReportingService::class)->summary('USD', now(), now()->addWeek());

        $this->assertSame(100_000, $summary['booked_minor']);
        $this->assertSame(40_000, $summary['collected_minor']);
        $this->assertSame(60_000, $summary['receivable_minor']);
        $this->assertSame(10_000, $summary['cost_minor']);
        $this->assertSame(10_000, $summary['commission_minor']);
        $this->assertSame(80_000, $summary['margin_minor']);
    }

    public function test_csv_export_neutralizes_spreadsheet_formulas(): void
    {
        $csv = app(SafeCsvExporter::class)->export(
            ['Guest', 'Note'],
            [['=HYPERLINK("https://bad.example")', '+1-1'], ['Safe', '@SUM(1,1)']],
        );

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'+1-1", $csv);
        $this->assertStringContainsString("'@SUM", $csv);
    }

    public function test_integration_configuration_stores_only_secret_references_and_remains_tenant_scoped(): void
    {
        [$tenantA] = $this->tenantEnvironment(authenticate: false);
        $service = app(IntegrationConnectionService::class);
        $connection = $service->configure('Primary calendar', 'calendar', ['calendar_id' => 'ops'], 'vault://tenant-a/calendar');
        $this->assertSame('configured', $connection->status);
        $this->assertSame('vault://tenant-a/calendar', $connection->secret_reference);

        try {
            $service->configure('Unsafe', 'email', ['api_key' => 'plaintext'], null);
            $this->fail('Plaintext secret configuration must fail.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }
        try {
            $service->configure('Nested unsafe', 'email', ['credentials' => ['access_token' => 'plaintext']], null);
            $this->fail('Nested plaintext secret configuration must fail.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }
        try {
            $service->configure('Raw secret', 'email', [], 'sk-this-is-not-a-reference');
            $this->fail('Raw secrets must not be accepted as secret references.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        app(TenantContext::class)->clear();
        $this->tenantEnvironment(authenticate: false);
        $this->assertFalse(IntegrationConnection::query()->whereKey($connection->id)->exists());
        $this->assertNotEmpty($tenantA->id);
    }

    public function test_document_versions_are_immutable_and_files_use_private_tenant_paths(): void
    {
        Storage::fake('local');
        [$tenant, $property] = $this->tenantEnvironment();
        $reservation = Reservation::factory()->create(['property_id' => $property->id]);
        $service = app(DocumentService::class);
        $versionOne = $service->createTemplate('Itinerary', 'itinerary', ['sections' => ['stay']]);
        $versionTwo = $service->createTemplate('Itinerary', 'itinerary', ['sections' => ['stay', 'activities']]);
        $document = $service->store($versionTwo, '%PDF-test', $reservation);

        $this->assertSame(1, $versionOne->version);
        $this->assertSame(2, $versionTwo->version);
        $this->assertFalse($versionOne->fresh()->is_active);
        $this->assertStringStartsWith("tenants/{$tenant->id}/documents/{$reservation->id}/", $document->storage_path);
        $this->assertSame(hash('sha256', '%PDF-test'), $document->checksum);
        Storage::disk('local')->assertExists($document->storage_path);

        try {
            $versionOne->update(['definition' => ['tampered' => true]]);
            $this->fail('Document template versions must be immutable.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }
    }

    public function test_exchange_rate_conversion_uses_an_immutable_decimal_snapshot(): void
    {
        $this->tenantEnvironment();
        $service = app(ExchangeRateService::class);
        $snapshot = $service->snapshot('USD', 'ARS', '1234.56789', 'central-bank', now());

        $this->assertSame(123_457, $service->convertMinor(100, $snapshot));
        $this->assertSame('1234.5678900000', $snapshot->rate);
        $this->assertSame('central-bank', $snapshot->source);

        $this->expectException(LogicException::class);
        $snapshot->update(['rate' => '1.0000000000']);
    }

    public function test_published_message_versions_are_immutable_idempotent_and_suppression_aware(): void
    {
        [, $property] = $this->tenantEnvironment();
        $guest = Guest::factory()->create(['email' => 'traveler@example.com']);
        $reservation = Reservation::factory()->create(['property_id' => $property->id, 'primary_guest_id' => $guest->id]);
        $template = MessageTemplate::query()->create([
            'key' => 'reservation-confirmed', 'name' => 'Reservation confirmed', 'channel' => 'email', 'is_active' => true,
        ]);
        $service = app(MessageTemplateService::class);
        $version = $service->createVersion($template, 'en', 'Welcome {{ guest.first_name }}', 'Reservation {{ reservation.code }} is confirmed.');
        $service->publish($version);
        $first = $service->queue($template, $guest, 'en', 'confirm:'.$reservation->id, [
            'guest' => ['first_name' => $guest->first_name],
            'reservation' => ['code' => $reservation->confirmation_number],
        ], $reservation);
        $second = $service->queue($template, $guest, 'en', 'confirm:'.$reservation->id, [], $reservation);

        $this->assertSame($first->id, $second->id);
        $this->assertStringContainsString($guest->first_name, $first->subject);
        $this->assertDatabaseCount('communications', 1);
        $this->assertDatabaseCount('outbox', 1);

        try {
            $version->update(['body' => 'Mutated']);
            $this->fail('Published content must be immutable.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }

        CommunicationSuppression::query()->create([
            'channel' => 'email',
            'recipient_hash' => hash('sha256', 'traveler@example.com'),
            'reason' => 'unsubscribe',
        ]);
        $this->expectException(DomainException::class);
        $service->queue($template, $guest, 'en', 'another-message', [], $reservation);
    }

    public function test_extended_operations_api_enforces_retail_and_finance_role_boundaries(): void
    {
        [$tenant, $property] = $this->tenantEnvironment();
        $location = StockLocation::query()->create(['property_id' => $property->id, 'name' => 'Shop', 'code' => 'SHOP']);
        $headers = ['X-Tenant-ID' => $tenant->id, 'Idempotency-Key' => 'catalog-create-0001'];
        $itemId = $this->withHeaders($headers)->postJson('/api/v1/catalog', [
            'sku' => 'MUG-001', 'name' => 'Lodge mug', 'type' => 'retail', 'currency' => 'USD',
            'price_minor' => 1800, 'cost_minor' => 700, 'track_stock' => true,
        ])->assertCreated()->json('data.id');
        $this->withHeaders(['X-Tenant-ID' => $tenant->id, 'Idempotency-Key' => 'stock-receipt-0001'])
            ->postJson('/api/v1/stock-receipts', [
                'catalog_item_id' => $itemId,
                'stock_location_id' => $location->id,
                'quantity_milli' => 3000,
                'reference' => 'RECEIPT-API',
            ])->assertCreated();
        $this->withHeaders(['X-Tenant-ID' => $tenant->id, 'Idempotency-Key' => 'retail-sale-0001'])
            ->postJson('/api/v1/retail-sales', [
                'stock_location_id' => $location->id,
                'reference' => 'SALE-API',
                'lines' => [['catalog_item_id' => $itemId, 'quantity_milli' => 1000]],
            ])->assertCreated()->assertJsonPath('data.total_minor', 1800);
        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/financial-summary')->assertOk();

        app(TenantContext::class)->clear();
        [$kitchenTenant] = $this->tenantEnvironment(MembershipRole::Kitchen);
        $this->withHeader('X-Tenant-ID', $kitchenTenant->id)->getJson('/api/v1/catalog')->assertForbidden();
        $this->withHeader('X-Tenant-ID', $kitchenTenant->id)->getJson('/api/v1/financial-summary')->assertForbidden();
    }

    public function test_guest_merge_preserves_an_alias_and_repoints_history_without_duplicate_party_rows(): void
    {
        [$tenant, $property] = $this->tenantEnvironment();
        $source = Guest::factory()->create(['email' => 'old@example.com', 'phone' => '+10001', 'preferences' => ['diet' => 'vegetarian']]);
        $target = Guest::factory()->create(['email' => null, 'phone' => null, 'preferences' => ['room' => 'quiet']]);
        $reservation = Reservation::factory()->create(['property_id' => $property->id, 'primary_guest_id' => $source->id]);
        foreach ([$source, $target] as $guest) {
            DB::table('reservation_guests')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'reservation_id' => $reservation->id,
                'guest_id' => $guest->id, 'role' => 'guest', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $merged = app(GuestMergeService::class)->merge($source, $target);

        $this->assertSame('old@example.com', $merged->email);
        $this->assertSame('vegetarian', data_get($merged->preferences, 'diet'));
        $this->assertSame('quiet', data_get($merged->preferences, 'room'));
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'primary_guest_id' => $target->id]);
        $this->assertDatabaseHas('guest_merge_aliases', ['guest_id' => $target->id, 'source_guest_id' => $source->id]);
        $this->assertDatabaseHas('guests', ['id' => $source->id, 'email' => null, 'merged_into_id' => $target->id]);
        $this->assertSame(1, DB::table('reservation_guests')->where('reservation_id', $reservation->id)->count());
    }

    public function test_opportunity_pipeline_requires_valid_transitions_and_a_reason_when_lost(): void
    {
        [, $property, $user] = $this->tenantEnvironment();
        $agency = Organization::query()->create([
            'name' => 'Andes Travel', 'type' => 'agency', 'commission_basis_points' => 1200, 'is_active' => true,
        ]);
        $opportunity = Opportunity::query()->create([
            'property_id' => $property->id, 'organization_id' => $agency->id, 'owner_id' => $user->id,
            'title' => 'Patagonia group', 'stage' => 'inquiry', 'currency' => 'USD', 'value_minor' => 250_000,
        ]);
        $service = app(OpportunityService::class);
        $qualified = $service->transition($opportunity, 'qualified');
        $this->assertSame('qualified', $qualified->stage);

        try {
            $service->transition($qualified, 'lost');
            $this->fail('A lost opportunity must include a reason.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('reason', $exception->getMessage());
        }
        $lost = $service->transition($qualified, 'lost', 'Dates unavailable');
        $this->assertSame('lost', $lost->stage);
        $this->assertSame('Dates unavailable', $lost->lost_reason);
    }

    public function test_sales_can_operate_the_tenant_crm_pipeline_but_finance_cannot(): void
    {
        [$tenant, $property] = $this->tenantEnvironment(MembershipRole::Sales);
        $organizationId = $this->withHeaders(['X-Tenant-ID' => $tenant->id, 'Idempotency-Key' => 'organization-api-0001'])
            ->postJson('/api/v1/organizations', [
                'name' => 'Pampas Journeys', 'type' => 'agency', 'commission_basis_points' => 800,
            ])->assertCreated()->json('data.id');
        $opportunityId = $this->withHeaders(['X-Tenant-ID' => $tenant->id, 'Idempotency-Key' => 'opportunity-api-0001'])
            ->postJson('/api/v1/opportunities', [
                'property_id' => $property->id,
                'organization_id' => $organizationId,
                'title' => 'Corporate retreat',
                'currency' => 'USD',
                'value_minor' => 500_000,
            ])->assertCreated()->json('data.id');
        $this->withHeaders(['X-Tenant-ID' => $tenant->id, 'Idempotency-Key' => 'opportunity-transition-0001'])
            ->postJson("/api/v1/opportunities/{$opportunityId}/transition", ['stage' => 'qualified'])
            ->assertOk()->assertJsonPath('data.stage', 'qualified');
        $this->withHeader('X-Tenant-ID', $tenant->id)->getJson('/api/v1/opportunities')
            ->assertOk()->assertJsonFragment(['id' => $opportunityId]);

        app(TenantContext::class)->clear();
        [$financeTenant] = $this->tenantEnvironment(MembershipRole::Finance);
        $this->withHeader('X-Tenant-ID', $financeTenant->id)->getJson('/api/v1/opportunities')->assertForbidden();
    }
}
