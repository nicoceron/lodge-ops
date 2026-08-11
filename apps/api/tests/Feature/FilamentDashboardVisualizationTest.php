<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Filament\Pages\FinanceDashboard;
use App\Filament\Pages\OperationsBoard;
use App\Filament\Widgets\FinanceOverview;
use App\Filament\Widgets\FinanceRevenueTrend;
use App\Filament\Widgets\LodgeCommandCenter;
use App\Filament\Widgets\LodgeFlowTrend;
use App\Filament\Widgets\LodgeOccupancyTrend;
use App\Filament\Widgets\LodgeReadinessOverview;
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
            ->assertSee('Occupancy now')
            ->assertSee('Arrival readiness')
            ->assertSee('Arrivals today')
            ->assertSee('Work at risk')
            ->assertDontSee('Guests in house')
            ->assertDontSee('Open work');
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

    public function test_finance_widgets_prioritize_contextual_metrics_and_a_revenue_collection_trend(): void
    {
        [$tenant, , $user] = $this->tenantEnvironment(MembershipRole::Finance, authenticate: false);
        $tenant->update(['currency' => 'USD', 'locale' => 'en_US']);
        $this->actingAs($user);
        Filament::setTenant($tenant);
        $parameters = [
            'start' => now()->startOfMonth()->toDateString(),
            'end' => now()->endOfMonth()->toDateString(),
            'displayCurrency' => 'USD',
        ];

        Livewire::test(FinanceOverview::class, $parameters)
            ->assertSee('Financial pulse')
            ->assertSee('Booked revenue')
            ->assertSee('Cash collected')
            ->assertSee('Receivables')
            ->assertSee('Gross margin')
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
