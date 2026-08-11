<?php

namespace App\Filament\Widgets;

use App\Enums\MembershipRole;
use App\Enums\ReservationStatus;
use App\Enums\TaskStatus;
use App\Models\OperationalTask;
use App\Models\Reservation;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class LodgeReadinessOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '30s';

    protected ?string $heading = "Today's lodge pulse";

    protected ?string $description = 'Live arrivals, occupancy and work-queue readiness in the property timezone.';

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
        $timezone = app(TenantContext::class)->tenant()->timezone;
        $start = CarbonImmutable::now($timezone)->startOfDay()->utc();
        $end = $start->addDay();

        $arrivals = Reservation::query()
            ->where('starts_at', '>=', $start)
            ->where('starts_at', '<', $end)
            ->whereIn('status', [ReservationStatus::Confirmed, ReservationStatus::CheckedIn])
            ->count();
        $departures = Reservation::query()
            ->where('ends_at', '>=', $start)
            ->where('ends_at', '<', $end)
            ->whereIn('status', [ReservationStatus::Confirmed, ReservationStatus::CheckedIn, ReservationStatus::CheckedOut])
            ->count();
        $inHouse = Reservation::query()
            ->where('status', ReservationStatus::CheckedIn)
            ->count();
        $openTasks = OperationalTask::query()
            ->whereNotIn('status', [TaskStatus::Done, TaskStatus::Cancelled])
            ->count();
        $overdueTasks = OperationalTask::query()
            ->whereNotIn('status', [TaskStatus::Done, TaskStatus::Cancelled])
            ->where('due_at', '<', now())
            ->count();

        return [
            Stat::make('Arrivals today', $arrivals)
                ->description("{$departures} departures")
                ->descriptionIcon('heroicon-m-arrow-right-start-on-rectangle')
                ->color($arrivals > 0 ? 'success' : 'gray'),
            Stat::make('Guests in house', $inHouse)
                ->description('Checked-in reservations')
                ->descriptionIcon('heroicon-m-key')
                ->color($inHouse > 0 ? 'info' : 'gray'),
            Stat::make('Open work', $openTasks)
                ->description($overdueTasks > 0 ? "{$overdueTasks} overdue" : 'Nothing overdue')
                ->descriptionIcon($overdueTasks > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($overdueTasks > 0 ? 'danger' : 'success'),
        ];
    }
}
