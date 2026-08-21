<?php

namespace Tests\Feature;

use App\Enums\AllocationStatus;
use App\Enums\MembershipRole;
use App\Enums\ReservationStatus;
use App\Filament\Pages\MasterCalendar;
use App\Filament\Pages\OperationsBoard;
use App\Models\Allocation;
use App\Models\ChecklistTemplate;
use App\Models\Program;
use App\Models\Reservation;
use App\Models\Resource;
use App\Services\ChecklistWorkflowService;
use App\Services\Projections\DashboardProjectionService;
use App\Services\SharedResourceAttentionService;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class OperationalAcceptanceFinalReviewTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_partial_shared_resource_requirement_renders_a_missing_slot_and_assignment_without_reallocating_existing_work(): void
    {
        [$tenant, $property, $manager, $membership] = $this->tenantEnvironment(MembershipRole::Manager, authenticate: false);
        $this->actingAs($manager);
        app(TenantContext::class)->set($tenant, $membership);
        Filament::setCurrentPanel(filament()->getPanel('admin'));
        Filament::setTenant($tenant, isQuiet: true);

        $category = $this->category($property, 'guide');
        $program = Program::query()->create([
            'property_id' => $property->id,
            'name' => 'Two-guide operation',
            'default_duration_minutes' => 360,
            'capacity' => 8,
            'price_minor' => 0,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $program->requirements()->create(['resource_category_id' => $category->id, 'minimum_quantity' => 2]);
        [$assignedGuide, $availableGuide] = Resource::factory()->guide()->count(2)->create([
            'property_id' => $property->id,
            'category_id' => $category->id,
        ])->all();
        $starts = now()->addDays(5)->startOfHour();
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'program_id' => $program->id,
            'status' => ReservationStatus::Confirmed,
            'starts_at' => $starts,
            'ends_at' => $starts->clone()->addHours(6),
            'adults' => 1,
            'children' => 0,
        ]);
        $existing = Allocation::query()->create([
            'reservation_id' => $reservation->id,
            'requested_category_id' => $category->id,
            'resource_id' => $assignedGuide->id,
            'status' => AllocationStatus::Confirmed,
            'starts_at' => $reservation->starts_at,
            'ends_at' => $reservation->ends_at,
            'quantity' => 1,
        ]);

        $row = app(SharedResourceAttentionService::class)
            ->build($starts->toImmutable()->subHour(), $starts->toImmutable()->addHours(7), $property->id, [])
            ->firstWhere('reservation_id', $reservation->id);
        $this->assertSame(2, $row['required']);
        $this->assertSame(1, $row['assigned']);
        $this->assertSame(1, $row['missing']);
        $this->assertNull($row['missing_allocation_id']);
        $this->assertSame($existing->id, $row['assignments'][0]['allocation_id']);

        Livewire::test(MasterCalendar::class)
            ->assertSee('Required 2 · assigned 1')
            ->assertSee('Missing 1 assignment')
            ->call('assignAttention', $reservation->id, $category->id, $availableGuide->id, null)
            ->assertHasNoErrors()
            ->assertSee('Required 2 · assigned 2')
            ->assertDontSee('Missing 1 assignment');

        $active = $reservation->allocations()->where('status', '!=', AllocationStatus::Released)->get();
        $this->assertCount(2, $active);
        $this->assertEqualsCanonicalizing([$assignedGuide->id, $availableGuide->id], $active->pluck('resource_id')->all());
        $this->assertSame(AllocationStatus::Confirmed, $existing->fresh()->status);
        $this->assertDatabaseHas('reservation_changes', [
            'reservation_id' => $reservation->id,
            'type' => 'resource_assigned',
        ]);
    }

    public function test_checklist_regeneration_superseded_tasks_are_absent_from_operations_and_dashboard_active_counts_cards_and_charts(): void
    {
        [, $property, $manager] = $this->tenantEnvironment(MembershipRole::Manager);
        $this->actingAs($manager);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'status' => ReservationStatus::Confirmed,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
        ]);
        $template = ChecklistTemplate::query()->create([
            'property_id' => $property->id,
            'name' => 'Superseded projection regression',
            'role' => 'operations',
        ]);
        $workflow = app(ChecklistWorkflowService::class);
        $first = $workflow->publish($template, [['title' => 'Superseded checklist task']], $manager->id);
        $workflow->generate($reservation, $first, $manager->id);
        $second = $workflow->publish($template, [['title' => 'Current checklist task']], $manager->id);
        $workflow->generate($reservation, $second, $manager->id);
        Cache::flush();

        $dashboard = app(DashboardProjectionService::class)->build();
        $this->assertSame(1, $dashboard['open_tasks']);
        $this->assertSame(1, $dashboard['overdue_tasks']);
        $this->assertSame(['Current checklist task'], collect($dashboard['tasks'])->pluck('title')->all());
        $this->assertSame(1, array_sum($dashboard['trend']['work_due']));

        request()->setUserResolver(fn () => $manager);
        $board = new class extends OperationsBoard
        {
            /** @return array<string, mixed> */
            public function exposedViewData(): array
            {
                return $this->getViewData();
            }
        };
        $boardData = $board->exposedViewData();
        $this->assertSame(['Current checklist task'], $boardData['tasks']->pluck('title')->all());
        $this->assertSame(1, $boardData['overdue']);
    }
}
