<?php

namespace App\Filament\Widgets;

use App\Enums\MembershipRole;
use App\Filament\Pages\MasterCalendar;
use App\Filament\Pages\OperationsBoard;
use App\Filament\Resources\OperationalTasks\OperationalTaskResource;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Services\Projections\DashboardProjectionService;
use App\Support\Tenancy\TenantContext;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class LodgeReadinessOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '30s';

    protected ?string $heading = "Today's lodge pulse";

    protected ?string $description = 'Current state with a 14-day operating trend. Open a card to reach the underlying workflow.';

    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return in_array(app(TenantContext::class)->membership()?->role, [
            MembershipRole::Administrator,
            MembershipRole::Manager,
            MembershipRole::Sales,
            MembershipRole::Operations,
        ], true);
    }

    protected function getStats(): array
    {
        $dashboard = app(DashboardProjectionService::class)->build();
        $trend = $dashboard['trend'];
        $arrivalReadinessUrl = OperationsBoard::canAccess()
            ? OperationsBoard::getUrl()
            : (ReservationResource::canViewAny() ? ReservationResource::getUrl() : null);

        return [
            Stat::make('Occupancy now', "{$dashboard['occupied_rooms']} of {$dashboard['active_rooms']} rooms")
                ->description("{$dashboard['occupancy_percent']}% occupied")
                ->descriptionIcon('heroicon-m-home-modern')
                ->chart(array_map('floatval', $trend['occupancy_percent']))
                ->color($dashboard['occupancy_percent'] > 0 ? 'info' : 'gray')
                ->url(MasterCalendar::getUrl()),
            Stat::make('Arrival readiness', "{$dashboard['readiness']['percent']}%")
                ->description("{$dashboard['readiness']['complete']} of {$dashboard['readiness']['total']} checks · {$dashboard['needs_attention']} stays need attention")
                ->descriptionIcon($dashboard['needs_attention'] > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->chart(array_map('floatval', $trend['attention']))
                ->color($dashboard['needs_attention'] > 0 ? 'warning' : 'success')
                ->url($arrivalReadinessUrl),
            Stat::make('Arrivals today', $dashboard['arrivals'])
                ->description("{$dashboard['departures']} departures today")
                ->descriptionIcon('heroicon-m-arrow-right-start-on-rectangle')
                ->chart(array_map('floatval', $trend['arrivals']))
                ->color($dashboard['arrivals'] > 0 ? 'info' : 'gray')
                ->url(ReservationResource::getUrl()),
            Stat::make('Work at risk', $dashboard['overdue_tasks'])
                ->description("{$dashboard['open_tasks']} open tasks")
                ->descriptionIcon($dashboard['overdue_tasks'] > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->chart(array_map('floatval', $trend['work_due']))
                ->color($dashboard['overdue_tasks'] > 0 ? 'danger' : 'success')
                ->url(OperationalTaskResource::getUrl()),
        ];
    }
}
