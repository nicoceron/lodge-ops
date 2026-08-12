<?php

namespace Tests\Feature;

use App\Enums\DepositStatus;
use App\Enums\MembershipRole;
use App\Enums\ReservationStatus;
use App\Enums\TaskStatus;
use App\Filament\Pages\FinanceDashboard;
use App\Filament\Pages\OperationsBoard;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Filament\Widgets\FinanceOverview;
use App\Filament\Widgets\FinanceRevenueTrend;
use App\Filament\Widgets\LodgeCommandCenter;
use App\Filament\Widgets\LodgeFlowTrend;
use App\Filament\Widgets\LodgeOccupancyTrend;
use App\Filament\Widgets\LodgeReadinessOverview;
use App\Models\Deposit;
use App\Models\OperationalTask;
use App\Models\Reservation;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class FilamentDashboardVisualizationTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_operational_overview_prioritizes_four_contextual_metrics(): void
    {
        [$tenant, , $user] = $this->tenantEnvironment(MembershipRole::Operations, authenticate: false);
        $this->actingAs($user);
        Filament::setTenant($tenant);

        Livewire::test(LodgeReadinessOverview::class)
            ->assertSee('Current state with recent and upcoming operating trends.')
            ->assertSee('Occupancy now')
            ->assertSee('Arrival readiness')
            ->assertSee('Arrivals today')
            ->assertSee('Work at risk')
            ->assertDontSee('Guests in house')
            ->assertDontSee('Open work');
    }

    public function test_arrival_readiness_renders_no_data_instead_of_a_false_one_hundred_percent(): void
    {
        [$tenant, , $user] = $this->tenantEnvironment(MembershipRole::Operations, authenticate: false);
        $this->actingAs($user);
        Filament::setTenant($tenant);

        Livewire::test(LodgeReadinessOverview::class)
            ->assertSee('Arrival readiness')
            ->assertSee('N/A')
            ->assertSee('No upcoming stays')
            ->assertDontSee('100%');
    }

    public function test_operational_dashboard_renders_two_accessible_decision_charts(): void
    {
        [$tenant, , $user] = $this->tenantEnvironment(MembershipRole::Operations, authenticate: false);
        $this->actingAs($user);
        Filament::setTenant($tenant);

        Livewire::test(LodgeFlowTrend::class)
            ->assertSee('Arrivals and departures')
            ->assertSee('Daily guest flow across the current 14-day operating window.')
            ->assertSeeHtml('role="img"')
            ->assertSeeHtml('aria-label="Arrivals and departures. Daily guest flow across the current 14-day operating window."');

        Livewire::test(LodgeOccupancyTrend::class)
            ->assertSee('Room occupancy')
            ->assertSee('Rooms in use by day across the current 14-day operating window.')
            ->assertSeeHtml('role="img"')
            ->assertSeeHtml('aria-label="Room occupancy. Rooms in use by day across the current 14-day operating window."');
    }

    public function test_command_center_surfaces_records_needing_action_instead_of_repeating_readiness_totals(): void
    {
        [$tenant, , $user] = $this->tenantEnvironment(MembershipRole::Operations, authenticate: false);
        $this->actingAs($user);
        Filament::setTenant($tenant);

        Livewire::test(LodgeCommandCenter::class)
            ->assertSee('Quick actions')
            ->assertSee('Stays needing attention')
            ->assertSee("Today's arrivals")
            ->assertSee('Action queue')
            ->assertDontSee('Next 7 days readiness')
            ->assertDontSee('checks complete');
    }

    public function test_sales_dashboard_does_not_link_to_the_restricted_operations_board(): void
    {
        [$tenant, , $user] = $this->tenantEnvironment(MembershipRole::Sales, authenticate: false);
        $this->actingAs($user);
        Filament::setTenant($tenant);
        $this->assertFalse(OperationsBoard::canAccess());

        Livewire::test(LodgeReadinessOverview::class)
            ->assertDontSeeHtml('href="'.e(OperationsBoard::getUrl()).'"');
        Livewire::test(LodgeCommandCenter::class)
            ->assertDontSee('Operations board');
    }

    public function test_sales_dashboard_does_not_render_operational_task_data_or_links(): void
    {
        [$tenant, $property, $user] = $this->tenantEnvironment(MembershipRole::Sales, authenticate: false);
        OperationalTask::query()->create([
            'property_id' => $property->id,
            'assignee_id' => $user->id,
            'title' => 'Private operations handoff',
            'status' => TaskStatus::Todo,
            'priority' => 'urgent',
        ]);
        $this->actingAs($user);
        Filament::setTenant($tenant);

        Livewire::test(LodgeReadinessOverview::class)
            ->assertDontSee('Work at risk');
        Livewire::test(LodgeCommandCenter::class)
            ->assertDontSee('Private operations handoff')
            ->assertDontSee('All tasks')
            ->assertDontSee('Action queue');
    }

    public function test_finance_widgets_prioritize_contextual_metrics_and_a_revenue_collection_trend(): void
    {
        [$tenant, $property, $user] = $this->tenantEnvironment(MembershipRole::Finance, authenticate: false);
        $tenant->update(['currency' => 'USD', 'locale' => 'en_US']);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'status' => ReservationStatus::Confirmed,
            'currency' => 'USD',
            'starts_at' => now(),
            'ends_at' => now()->addDays(2),
        ]);
        foreach (['manual' => now()->subDay(), 'balance' => now()->addDay()] as $scheduleType => $dueAt) {
            Deposit::query()->create([
                'reservation_id' => $reservation->id,
                'status' => DepositStatus::Due,
                'schedule_type' => $scheduleType,
                'currency' => 'USD',
                'amount_minor' => 10_000,
                'due_at' => $dueAt,
            ]);
        }
        $this->actingAs($user);
        Filament::setTenant($tenant);
        $parameters = [
            'start' => now()->startOfMonth()->toDateString(),
            'end' => now()->endOfMonth()->toDateString(),
            'displayCurrency' => 'USD',
        ];

        Livewire::test(FinanceOverview::class, $parameters)
            ->assertDontSeeHtml('wire:poll')
            ->assertSee('Financial pulse')
            ->assertSee('Booked revenue')
            ->assertSee('Cash collected')
            ->assertSee('Receivables')
            ->assertSee('Gross margin')
            ->assertSee('0% cash collected vs booked arrivals')
            ->assertSee('2 due · 1 overdue')
            ->assertDontSee('collection rate')
            ->assertDontSeeHtml('href="'.e(ReservationResource::getUrl()).'"')
            ->assertDontSee('Loaded costs')
            ->assertDontSee('Commission accruals');

        Livewire::test(FinanceRevenueTrend::class, $parameters)
            ->assertSee('Revenue vs cash collected')
            ->assertSee('Seven-month trend ending in the selected period, in the lodge currency.')
            ->assertSeeHtml('role="img"')
            ->assertSeeHtml('aria-label="Revenue vs cash collected. Seven-month trend ending in the selected period, in the lodge currency."');
    }

    public function test_finance_widgets_enforce_the_finance_role_boundary(): void
    {
        [, , $user] = $this->tenantEnvironment(MembershipRole::Operations, authenticate: false);
        $this->actingAs($user);

        $this->assertFalse(FinanceOverview::canView());
        $this->assertFalse(FinanceRevenueTrend::canView());
    }

    public function test_finance_page_places_native_insights_before_retained_audit_detail(): void
    {
        [$tenant, , $user] = $this->tenantEnvironment(MembershipRole::Finance, authenticate: false);
        $tenant->update(['currency' => 'USD', 'locale' => 'en_US']);
        $this->actingAs($user);

        $response = $this->get(FinanceDashboard::getUrl(['tenant' => $tenant]));

        $response->assertOk()
            ->assertSeeInOrder([
                'Reporting period',
                'Financial pulse',
                'Revenue vs cash collected',
                'Native currency totals',
                'Program performance',
                'Reconciliation',
                'Recent folios',
            ])
            ->assertDontSee('Collection rate')
            ->assertDontSee('Revenue trend');
    }
}
