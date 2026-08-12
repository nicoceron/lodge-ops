<?php

namespace App\Filament\Widgets;

use App\Enums\MembershipRole;
use App\Filament\Pages\MasterCalendar;
use App\Filament\Pages\OperationsBoard;
use App\Filament\Resources\OperationalTasks\OperationalTaskResource;
use App\Filament\Resources\Reservations\ReservationResource;
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
        ];
    }
}
