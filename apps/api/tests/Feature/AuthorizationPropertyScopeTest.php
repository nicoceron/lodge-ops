<?php

namespace Tests\Feature;

use App\Enums\AllocationStatus;
use App\Enums\MembershipRole;
use App\Models\Allocation;
use App\Models\CatalogItem;
use App\Models\Membership;
use App\Models\Opportunity;
use App\Models\Program;
use App\Models\Property;
use App\Models\Proposal;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\ServiceOccurrence;
use App\Models\StockLocation;
use App\Models\User;
use App\Services\BookingQuoteService;
use App\Services\ProposalService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class AuthorizationPropertyScopeTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_owner_and_kitchen_cannot_read_operational_catalogs(): void
    {
        foreach ([MembershipRole::Owner, MembershipRole::Kitchen] as $role) {
            [$tenant, $property] = $this->tenantEnvironment($role);
            $program = $this->program($property, "{$role->value} program");
            $resource = Resource::factory()->create(['property_id' => $property->id]);
            $occurrence = $this->occurrence($property, $program);
            $headers = ['X-Tenant-ID' => $tenant->id];

            $this->withHeaders($headers)->getJson('/api/v1/resources')->assertForbidden();
            $this->withHeaders($headers)->getJson("/api/v1/resources/{$resource->id}")->assertForbidden();
            $this->withHeaders($headers)->getJson('/api/v1/programs')->assertForbidden();
            $this->withHeaders($headers)->getJson("/api/v1/programs/{$program->id}")->assertForbidden();
            $this->withHeaders($headers)->getJson('/api/v1/service-occurrences')->assertForbidden();
            $this->withHeaders($headers)->getJson("/api/v1/service-occurrences/{$occurrence->id}")->assertForbidden();

            app(TenantContext::class)->clear();
        }
    }

    public function test_guide_only_reads_linked_resource_operational_surface(): void
    {
        [$tenant, $property, $guide] = $this->tenantEnvironment(MembershipRole::Guide);
        $program = $this->program($property, 'Guided activity');
        $ownResource = Resource::factory()->guide()->create([
            'property_id' => $property->id,
            'user_id' => $guide->id,
        ]);
        $otherGuide = User::factory()->create();
        Membership::factory()->create([
            'user_id' => $otherGuide->id,
            'property_id' => $property->id,
            'role' => MembershipRole::Guide,
        ]);
        $otherResource = Resource::factory()->guide()->create([
            'property_id' => $property->id,
            'user_id' => $otherGuide->id,
        ]);
        $ownOccurrence = $this->occurrence($property, $program, now()->addDay());
        $otherOccurrence = $this->occurrence($property, $program, now()->addDays(2));
        $this->linkOccurrence($property, $ownResource, $ownOccurrence);
        $this->linkOccurrence($property, $otherResource, $otherOccurrence);
        $headers = ['X-Tenant-ID' => $tenant->id];

        $this->withHeaders($headers)->getJson('/api/v1/resources?per_page=100')
            ->assertOk()
            ->assertJsonFragment(['id' => $ownResource->id])
            ->assertJsonMissing(['id' => $otherResource->id]);
        $this->withHeaders($headers)->getJson("/api/v1/resources/{$ownResource->id}")->assertOk();
        $this->withHeaders($headers)->getJson("/api/v1/resources/{$otherResource->id}")->assertForbidden();
        $this->withHeaders($headers)->getJson('/api/v1/programs')->assertForbidden();
        $this->withHeaders($headers)->getJson('/api/v1/service-occurrences?per_page=100')
            ->assertOk()
            ->assertJsonFragment(['id' => $ownOccurrence->id])
            ->assertJsonMissing(['id' => $otherOccurrence->id]);
        $this->withHeaders($headers)->getJson("/api/v1/service-occurrences/{$ownOccurrence->id}")->assertOk();
        $this->withHeaders($headers)->getJson("/api/v1/service-occurrences/{$otherOccurrence->id}")->assertForbidden();
        $suggestionQuery = http_build_query([
            'category_id' => $ownResource->category_id,
            'starts_at' => now()->addWeek()->toIso8601String(),
            'ends_at' => now()->addWeek()->addHour()->toIso8601String(),
        ], encoding_type: PHP_QUERY_RFC3986);
        $this->withHeaders($headers)->getJson('/api/v1/resources/suggestions?'.$suggestionQuery)
            ->assertForbidden();
    }

    public function test_administrator_and_manager_keep_tenant_wide_operational_visibility(): void
    {
        foreach ([MembershipRole::Administrator, MembershipRole::Manager] as $role) {
            [$tenant, $property] = $this->tenantEnvironment($role);
            $otherProperty = Property::factory()->for($tenant)->create();
            $this->category($otherProperty, 'room');
            $program = $this->program($otherProperty, "{$role->value} cross-property program");
            $resource = Resource::factory()->create(['property_id' => $otherProperty->id]);
            $occurrence = $this->occurrence($otherProperty, $program);
            $headers = ['X-Tenant-ID' => $tenant->id];

            $this->withHeaders($headers)->getJson('/api/v1/resources?per_page=100')
                ->assertOk()->assertJsonFragment(['id' => $resource->id]);
            $this->withHeaders($headers)->getJson('/api/v1/programs?per_page=100')
                ->assertOk()->assertJsonFragment(['id' => $program->id]);
            $this->withHeaders($headers)->getJson('/api/v1/service-occurrences?per_page=100')
                ->assertOk()->assertJsonFragment(['id' => $occurrence->id]);

            $this->assertNotSame($property->id, $otherProperty->id);
            app(TenantContext::class)->clear();
        }
    }

    public function test_proposals_are_scoped_for_lists_creates_reads_and_property_changes(): void
    {
        [$tenant, $property] = $this->tenantEnvironment(MembershipRole::Sales);
        $otherProperty = Property::factory()->for($tenant)->create();
        $own = $this->proposal($property);
        $other = Proposal::query()->create([
            'property_id' => $otherProperty->id, 'reference' => 'LEGACY-SCOPE', 'version' => 1,
            'status' => 'draft', 'starts_at' => now()->addMonth(), 'ends_at' => now()->addMonth()->addDay(),
            'adults' => 1, 'children' => 0, 'currency' => 'USD', 'total_minor' => 1, 'tax_minor' => 0,
            'snapshot' => ['schema_version' => 1, 'lines' => []],
        ]);
        $headers = ['X-Tenant-ID' => $tenant->id];

        $this->withHeaders($headers)->getJson('/api/v1/proposals?per_page=100')
            ->assertOk()
            ->assertJsonFragment(['id' => $own->id])
            ->assertJsonMissing(['id' => $other->id]);
        $this->withHeaders($headers)->getJson("/api/v1/proposals/{$other->id}")->assertForbidden();
        $this->withHeaders($headers)->postJson('/api/v1/proposals', [
            'property_id' => $otherProperty->id,
            'booking_quote_id' => $own->booking_quote_id,
        ])
            ->assertForbidden();
        $this->withHeaders($headers)->patchJson("/api/v1/proposals/{$own->id}", [
            'property_id' => $otherProperty->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('property_id');
    }

    public function test_stock_and_retail_writes_reject_cross_property_records(): void
    {
        [$tenant, $property] = $this->tenantEnvironment(MembershipRole::Operations);
        $otherProperty = Property::factory()->for($tenant)->create();
        $ownLocation = StockLocation::query()->create(['property_id' => $property->id, 'name' => 'Own shop', 'code' => 'OWN']);
        $otherLocation = StockLocation::query()->create(['property_id' => $otherProperty->id, 'name' => 'Other shop', 'code' => 'OTHER']);
        $otherReservation = Reservation::factory()->create(['property_id' => $otherProperty->id]);
        $item = CatalogItem::query()->create([
            'sku' => 'SCOPE-ITEM',
            'name' => 'Scope item',
            'type' => 'retail',
            'currency' => 'USD',
            'price_minor' => 1000,
            'cost_minor' => 500,
            'track_stock' => false,
            'is_active' => true,
        ]);
        $headers = ['X-Tenant-ID' => $tenant->id];

        $this->withHeaders($headers)->postJson('/api/v1/stock-receipts', [
            'catalog_item_id' => $item->id,
            'stock_location_id' => $otherLocation->id,
            'quantity_milli' => 1000,
            'reference' => 'CROSS-STOCK',
        ])->assertForbidden();
        $this->withHeaders($headers)->postJson('/api/v1/retail-sales', [
            'stock_location_id' => $otherLocation->id,
            'reference' => 'CROSS-SALE-LOCATION',
            'lines' => [['catalog_item_id' => $item->id, 'quantity_milli' => 1000]],
        ])->assertForbidden();
        $this->withHeaders($headers)->postJson('/api/v1/retail-sales', [
            'stock_location_id' => $ownLocation->id,
            'reservation_id' => $otherReservation->id,
            'reference' => 'CROSS-SALE-RESERVATION',
            'lines' => [['catalog_item_id' => $item->id, 'quantity_milli' => 1000]],
        ])->assertForbidden();

        $this->assertDatabaseMissing('stock_movements', ['reference' => 'CROSS-STOCK']);
        $this->assertDatabaseMissing('retail_sales', ['reference' => 'CROSS-SALE-LOCATION']);
        $this->assertDatabaseMissing('retail_sales', ['reference' => 'CROSS-SALE-RESERVATION']);
    }

    public function test_tenant_wide_retail_write_still_rejects_mismatched_properties(): void
    {
        [$tenant, $property] = $this->tenantEnvironment(MembershipRole::Manager);
        $otherProperty = Property::factory()->for($tenant)->create();
        $location = StockLocation::query()->create(['property_id' => $property->id, 'name' => 'Main shop', 'code' => 'MAIN']);
        $reservation = Reservation::factory()->create(['property_id' => $otherProperty->id]);
        $item = CatalogItem::query()->create([
            'sku' => 'MISMATCH-ITEM', 'name' => 'Mismatch item', 'type' => 'retail', 'currency' => 'USD',
            'price_minor' => 1000, 'cost_minor' => 0, 'track_stock' => false, 'is_active' => true,
        ]);

        $this->withHeader('X-Tenant-ID', $tenant->id)->postJson('/api/v1/retail-sales', [
            'stock_location_id' => $location->id,
            'reservation_id' => $reservation->id,
            'reference' => 'TENANT-WIDE-MISMATCH',
            'lines' => [['catalog_item_id' => $item->id, 'quantity_milli' => 1000]],
        ])->assertUnprocessable()->assertJsonValidationErrors('reservation_id');
    }

    public function test_cost_writes_require_records_in_the_membership_property(): void
    {
        [$tenant, $property] = $this->tenantEnvironment(MembershipRole::Finance);
        $otherProperty = Property::factory()->for($tenant)->create();
        $otherReservation = Reservation::factory()->create(['property_id' => $otherProperty->id]);
        $ownReservation = Reservation::factory()->create(['property_id' => $property->id]);
        $otherProgram = $this->program($otherProperty, 'Other property cost program');
        $otherStaff = User::factory()->create();
        Membership::factory()->create([
            'user_id' => $otherStaff->id,
            'property_id' => $otherProperty->id,
            'role' => MembershipRole::Guide,
        ]);
        $payload = [
            'kind' => 'actual',
            'category' => 'guide',
            'description' => 'Cross-property guide cost',
            'currency' => 'USD',
            'amount_minor' => 10000,
            'occurred_at' => now()->toIso8601String(),
        ];
        $headers = ['X-Tenant-ID' => $tenant->id];

        $this->withHeaders($headers)->postJson('/api/v1/costs', $payload + ['reservation_id' => $otherReservation->id])
            ->assertForbidden();
        $this->withHeaders($headers)->postJson('/api/v1/costs', $payload + ['program_id' => $otherProgram->id])
            ->assertForbidden();
        $this->withHeaders($headers)->postJson('/api/v1/costs', $payload + [
            'reservation_id' => $ownReservation->id,
            'staff_user_id' => $otherStaff->id,
        ])->assertForbidden();
        $this->withHeaders($headers)->postJson('/api/v1/costs', $payload)
            ->assertForbidden();

        $this->assertNotSame($property->id, $otherProperty->id);
        $this->assertDatabaseCount('cost_records', 0);
    }

    public function test_opportunity_pipeline_is_scoped_to_membership_property(): void
    {
        [$tenant, $property, $user] = $this->tenantEnvironment(MembershipRole::Sales);
        $otherProperty = Property::factory()->for($tenant)->create();
        $own = $this->opportunity($property, $user, 'Own opportunity');
        $other = $this->opportunity($otherProperty, $user, 'Other opportunity');
        $headers = ['X-Tenant-ID' => $tenant->id];

        $this->withHeaders($headers)->getJson('/api/v1/opportunities')
            ->assertOk()
            ->assertJsonFragment(['id' => $own->id])
            ->assertJsonMissing(['id' => $other->id]);
        $this->withHeaders($headers)->postJson('/api/v1/opportunities', [
            'property_id' => $otherProperty->id,
            'title' => 'Forbidden opportunity',
            'currency' => 'USD',
        ])->assertForbidden();
        $this->withHeaders($headers)->postJson("/api/v1/opportunities/{$other->id}/transition", [
            'stage' => 'qualified',
        ])->assertForbidden();
        $this->assertSame('inquiry', $other->refresh()->stage);
    }

    public function test_resource_suggestions_reject_cross_property_inputs(): void
    {
        [$tenant, $property] = $this->tenantEnvironment(MembershipRole::Operations);
        $otherProperty = Property::factory()->for($tenant)->create();
        $ownCategory = $this->category($property, 'guide');
        $otherCategory = $this->category($otherProperty, 'guide');
        $headers = ['X-Tenant-ID' => $tenant->id];

        $query = fn (array $parameters): string => http_build_query($parameters + [
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addHour()->toIso8601String(),
        ], encoding_type: PHP_QUERY_RFC3986);

        $this->withHeaders($headers)->getJson('/api/v1/resources/suggestions?'.$query(['category_id' => $otherCategory->id]))
            ->assertForbidden();
        $this->withHeaders($headers)->getJson('/api/v1/resources/suggestions?'.$query([
            'category_id' => $ownCategory->id,
            'property_id' => $otherProperty->id,
        ]))
            ->assertForbidden();
        $this->withHeaders($headers)->getJson('/api/v1/resources/suggestions?'.$query(['category_id' => $ownCategory->id]))
            ->assertOk();
    }

    private function program(Property $property, string $name): Program
    {
        return Program::query()->create([
            'property_id' => $property->id,
            'name' => $name,
            'currency' => 'USD',
            'is_active' => true,
        ]);
    }

    private function occurrence(Property $property, Program $program, ?CarbonInterface $startsAt = null): ServiceOccurrence
    {
        $startsAt ??= now()->addDay();

        return ServiceOccurrence::query()->create([
            'property_id' => $property->id,
            'program_id' => $program->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHours(2),
            'capacity' => 4,
        ]);
    }

    private function linkOccurrence(Property $property, Resource $resource, ServiceOccurrence $occurrence): void
    {
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'starts_at' => $occurrence->starts_at,
            'ends_at' => $occurrence->ends_at,
        ]);
        Allocation::query()->create([
            'reservation_id' => $reservation->id,
            'resource_id' => $resource->id,
            'service_occurrence_id' => $occurrence->id,
            'status' => AllocationStatus::Confirmed,
            'starts_at' => $occurrence->starts_at,
            'ends_at' => $occurrence->ends_at,
            'quantity' => 1,
        ]);
    }

    private function proposal(Property $property): Proposal
    {
        return app(ProposalService::class)->createDraft($this->proposalPayload($property), auth()->id());
    }

    /** @return array<string, mixed> */
    private function proposalPayload(Property $property): array
    {
        $category = $this->category($property, 'room');
        $resource = Resource::factory()->create(['property_id' => $property->id, 'category_id' => $category->id, 'capacity' => 4]);
        $plan = RatePlan::query()->create([
            'property_id' => $property->id, 'name' => 'Scoped proposal '.str()->ulid(),
            'currency' => 'USD', 'maximum_occupancy' => 4,
        ]);
        RateRule::query()->create(['rate_plan_id' => $plan->id, 'resource_category_id' => $category->id, 'amount_minor' => 10_000]);
        $plan->forceFill(['state' => 'published', 'published_at' => now()])->save();
        $quote = app(BookingQuoteService::class)->create([
            'property_id' => $property->id, 'rate_plan_id' => $plan->id,
            'resource_category_id' => $category->id, 'resource_id' => $resource->id,
            'starts_at' => now()->addMonth(), 'ends_at' => now()->addMonth()->addDays(3),
            'adults' => 2, 'children' => 0,
        ]);

        return [
            'property_id' => $property->id,
            'booking_quote_id' => $quote->id,
        ];
    }

    private function opportunity(Property $property, User $owner, string $title): Opportunity
    {
        return Opportunity::query()->create([
            'property_id' => $property->id,
            'owner_id' => $owner->id,
            'title' => $title,
            'stage' => 'inquiry',
            'currency' => 'USD',
            'value_minor' => 10000,
        ]);
    }
}
