<?php

namespace Tests\Feature;

use App\Filament\Pages\MasterCalendar;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\Tenant;
use App\Services\AllocationWorkflowService;
use App\Services\Projections\CalendarProjectionService;
use App\Services\SharedResourceAttentionService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SpareSharedResourceForAssignSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Tests\TestCase;

class SpareSharedResourceForAssignSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        Filament::setTenant(null, isQuiet: true);
        Filament::setCurrentPanel(null);

        parent::tearDown();
    }

    public function test_spare_guide_gives_inhouse_a_conflict_free_assign_suggestion(): void
    {
        $this->seed(DatabaseSeeder::class);

        $tenant = Tenant::query()->where('slug', 'demo-lodge')->firstOrFail();
        $membership = Membership::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('role', 'administrator')
            ->firstOrFail();
        app(TenantContext::class)->set($tenant, $membership);

        $usdPaymentsBefore = Payment::query()->where('currency', 'USD')->count();
        $usdReservationsBefore = Reservation::query()->where('currency', 'USD')->count();
        $this->assertGreaterThan(0, $usdPaymentsBefore);
        $this->assertGreaterThan(0, $usdReservationsBefore);

        $inHouse = Reservation::query()->where('confirmation_number', 'RSV-DEMO-INHOUSE')->firstOrFail();
        $this->assertSame(0, $this->missingSuggestionsFor($inHouse, $membership)->count());

        $this->seed(SpareSharedResourceForAssignSeeder::class);

        $spare = Resource::query()->where('code', SpareSharedResourceForAssignSeeder::RESOURCE_CODE)->firstOrFail();
        $this->assertSame($inHouse->property_id, $spare->property_id);
        $this->assertTrue($spare->is_active);
        $this->assertFalse($spare->isBuyout());

        $suggestions = $this->missingSuggestionsFor($inHouse, $membership);
        $this->assertNotEmpty($suggestions);
        $this->assertTrue($suggestions->contains(fn (array $suggestion): bool => $suggestion['id'] === $spare->id));

        $this->actingAs($membership->user);
        Filament::setCurrentPanel(filament()->getPanel('admin'));
        Filament::setTenant($tenant, isQuiet: true);

        Livewire::test(MasterCalendar::class)
            ->assertSee('RSV-DEMO-INHOUSE')
            ->assertSee('Assign '.$spare->name);

        $this->seed(SpareSharedResourceForAssignSeeder::class);
        $this->assertSame(1, Resource::query()->where('code', SpareSharedResourceForAssignSeeder::RESOURCE_CODE)->count());
        $this->assertSame($usdPaymentsBefore, Payment::query()->where('currency', 'USD')->count());
        $this->assertSame($usdReservationsBefore, Reservation::query()->where('currency', 'USD')->count());
    }

    public function test_second_spare_guide_lets_inhouse_clear_fishing_guide_attention_with_two_quantity_one_assigns(): void
    {
        $this->seed(DatabaseSeeder::class);

        $tenant = Tenant::query()->where('slug', 'demo-lodge')->firstOrFail();
        $membership = Membership::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('role', 'administrator')
            ->firstOrFail();
        app(TenantContext::class)->set($tenant, $membership);

        $usdPaymentsBefore = Payment::query()->where('currency', 'USD')->count();
        $usdReservationsBefore = Reservation::query()->where('currency', 'USD')->count();

        $this->seed(SpareSharedResourceForAssignSeeder::class);

        $first = Resource::query()->where('code', SpareSharedResourceForAssignSeeder::RESOURCE_CODE)->firstOrFail();
        $second = Resource::query()->where('code', SpareSharedResourceForAssignSeeder::SECOND_RESOURCE_CODE)->firstOrFail();
        $this->assertSame($first->property_id, $second->property_id);
        $this->assertTrue($second->is_active);
        $this->assertFalse($second->isBuyout());
        $this->assertSame(1, $second->capacity);
        $this->assertSame(['fishing', 'hunting'], $second->attributes['capabilities'] ?? null);
        $this->assertSame(['en', 'es'], $second->attributes['languages'] ?? null);

        $inHouse = Reservation::query()->where('confirmation_number', 'RSV-DEMO-INHOUSE')->firstOrFail();
        $row = $this->attentionRow($inHouse, $membership);
        $this->assertNotNull($row);
        $this->assertSame(2, $row['required']);
        $this->assertSame(0, $row['assigned']);
        $this->assertSame(2, $row['missing']);
        $suggestionIds = collect($row['missing_suggestions'])->pluck('id');
        $this->assertTrue($suggestionIds->contains($first->id));
        $this->assertTrue($suggestionIds->contains($second->id));

        $buyout = Reservation::query()->where('confirmation_number', 'RSV-DEMO-BUYOUT')->firstOrFail();
        $buyoutRow = $this->attentionRow($buyout, $membership);
        $this->assertSame([], $buyoutRow['missing_suggestions'] ?? []);

        $this->actingAs($membership->user);
        Filament::setCurrentPanel(filament()->getPanel('admin'));
        Filament::setTenant($tenant, isQuiet: true);

        Livewire::test(MasterCalendar::class)
            ->assertSee('RSV-DEMO-INHOUSE')
            ->assertSee('Assign '.$first->name)
            ->assertSee('Assign '.$second->name);

        app(AllocationWorkflowService::class)->create($inHouse, [
            'requested_category_id' => $row['category_id'],
            'resource_id' => $first->id,
            'starts_at' => $inHouse->starts_at,
            'ends_at' => $inHouse->ends_at,
            'quantity' => 1,
        ], $membership->user_id, 'Workbench quantity-1 assign.', true);

        $afterFirst = $this->attentionRow($inHouse->fresh(['allocations.resource.category', 'program.requirements.category']), $membership);
        $this->assertNotNull($afterFirst);
        $this->assertSame(1, $afterFirst['assigned']);
        $this->assertSame(1, $afterFirst['missing']);
        $remaining = collect($afterFirst['missing_suggestions'])->pluck('id');
        $this->assertFalse($remaining->contains($first->id));
        $this->assertTrue($remaining->contains($second->id));

        app(AllocationWorkflowService::class)->create($inHouse, [
            'requested_category_id' => $row['category_id'],
            'resource_id' => $second->id,
            'starts_at' => $inHouse->starts_at,
            'ends_at' => $inHouse->ends_at,
            'quantity' => 1,
        ], $membership->user_id, 'Workbench quantity-1 assign.', true);

        $cleared = $this->attentionRow($inHouse->fresh(['allocations.resource.category', 'program.requirements.category']), $membership);
        $this->assertNotNull($cleared);
        $this->assertSame(2, $cleared['assigned']);
        $this->assertSame(0, $cleared['missing']);
        $this->assertSame('healthy', $cleared['attention_state']);

        $this->seed(SpareSharedResourceForAssignSeeder::class);
        $this->assertSame(1, Resource::query()->where('code', SpareSharedResourceForAssignSeeder::RESOURCE_CODE)->count());
        $this->assertSame(1, Resource::query()->where('code', SpareSharedResourceForAssignSeeder::SECOND_RESOURCE_CODE)->count());
        $this->assertSame($usdPaymentsBefore, Payment::query()->where('currency', 'USD')->count());
        $this->assertSame($usdReservationsBefore, Reservation::query()->where('currency', 'USD')->count());
    }

    public function test_spare_guide_seeder_can_be_reversed_without_dropping_usd_history(): void
    {
        $this->seed(DatabaseSeeder::class);
        $tenant = Tenant::query()->where('slug', 'demo-lodge')->firstOrFail();
        $membership = Membership::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('role', 'administrator')
            ->firstOrFail();
        app(TenantContext::class)->set($tenant, $membership);

        $usdPayments = Payment::query()->where('currency', 'USD')->count();
        $this->seed(SpareSharedResourceForAssignSeeder::class);
        $this->assertTrue(Resource::query()->where('code', SpareSharedResourceForAssignSeeder::RESOURCE_CODE)->exists());
        $this->assertTrue(Resource::query()->where('code', SpareSharedResourceForAssignSeeder::SECOND_RESOURCE_CODE)->exists());

        SpareSharedResourceForAssignSeeder::reverse();

        $this->assertFalse(Resource::query()->where('code', SpareSharedResourceForAssignSeeder::RESOURCE_CODE)->exists());
        $this->assertFalse(Resource::query()->where('code', SpareSharedResourceForAssignSeeder::SECOND_RESOURCE_CODE)->exists());
        $this->assertSame($usdPayments, Payment::query()->where('currency', 'USD')->count());
        $this->assertTrue(Reservation::query()->where('confirmation_number', 'RSV-DEMO-INHOUSE')->exists());
        $this->assertTrue(Reservation::query()->where('confirmation_number', 'RSV-DEMO-BUYOUT')->exists());
    }

    /** @return Collection<int, array<string, mixed>> */
    private function missingSuggestionsFor(Reservation $reservation, Membership $membership): Collection
    {
        return collect($this->attentionRow($reservation, $membership)['missing_suggestions'] ?? []);
    }

    /** @return array<string, mixed>|null */
    private function attentionRow(Reservation $reservation, Membership $membership): ?array
    {
        $timezone = $reservation->property->timezone;
        $start = CarbonImmutable::now($timezone)->startOfDay()->utc();
        $end = CarbonImmutable::now($timezone)->addDays(13)->startOfDay()->utc();
        $conflicts = app(CalendarProjectionService::class)->build($start, $end, $membership->user, $reservation->property_id)['summary']['hard_conflict_reservation_ids'] ?? [];

        return app(SharedResourceAttentionService::class)
            ->build($start, $end, $reservation->property_id, $conflicts)
            ->first(fn (array $row): bool => $row['reservation_id'] === $reservation->id && ($row['category_slug'] ?? null) === 'guide');
    }
}
