<?php

namespace App\Filament\Widgets;

use App\Enums\MembershipRole;
use App\Filament\Pages\MasterCalendar;
use App\Filament\Pages\OperationsBoard;
use App\Filament\Resources\OperationalTasks\OperationalTaskResource;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Models\CommunicationDeliveryEvent;
use App\Models\DeliveryAttempt;
use App\Models\SchedulerHeartbeat;
use App\Services\Projections\DashboardProjectionService;
use App\Support\Tenancy\TenantContext;
use Filament\Widgets\Widget;

class LodgeCommandCenter extends Widget
{
    protected string $view = 'filament.widgets.lodge-command-center';

    protected static bool $isLazy = true;

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return in_array(app(TenantContext::class)->membership()?->role, [
            MembershipRole::Administrator,
            MembershipRole::Manager,
            MembershipRole::Sales,
            MembershipRole::Operations,
        ], true);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $context = app(TenantContext::class);
        $dashboard = app(DashboardProjectionService::class)->build();
        $heartbeat = SchedulerHeartbeat::query()->find('reservation-milestones');

        return [
            'dashboard' => $dashboard,
            'timezone' => $context->tenant()->timezone,
            'urls' => [
                'calendar' => MasterCalendar::getUrl(),
                'operations' => OperationsBoard::getUrl(),
                'reservations' => ReservationResource::getUrl(),
                'tasks' => OperationalTaskResource::getUrl(),
            ],
            'canAccessOperationsBoard' => OperationsBoard::canAccess(),
            'canAccessTasks' => OperationalTaskResource::canViewAny(),
            'communicationHealth' => [
                'scheduler_stale' => $heartbeat === null || $heartbeat->last_seen_at->isBefore(now()->subMinutes(3)),
                'scheduler_last_seen_at' => $heartbeat?->last_seen_at,
                'stranded_events' => CommunicationDeliveryEvent::query()->whereNull('processed_at')
                    ->whereIn('processing_state', ['pending', 'failed'])->where('received_at', '<=', now()->subMinutes(5))->count(),
                'expired_uncertain' => DeliveryAttempt::query()->where('status', 'outcome_uncertain')
                    ->where('reconcile_after', '<=', now())->count(),
            ],
        ];
    }
}
