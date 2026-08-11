<?php

namespace App\Filament\Widgets;

use App\Enums\MembershipRole;
use App\Services\Projections\DashboardProjectionService;
use App\Support\Tenancy\TenantContext;
use Filament\Widgets\ChartWidget;

class LodgeOccupancyTrend extends ChartWidget
{
    protected ?string $heading = 'Room occupancy';

    protected ?string $description = 'Rooms in use by day across the current 14-day operating window.';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = [
        'md' => 1,
        '2xl' => 2,
    ];

    protected ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        return in_array(app(TenantContext::class)->membership()?->role, [
            MembershipRole::Administrator,
            MembershipRole::Manager,
            MembershipRole::Sales,
            MembershipRole::Operations,
        ], true);
    }

    protected function getData(): array
    {
        $trend = app(DashboardProjectionService::class)->build()['trend'];

        return [
            'datasets' => [
                [
                    'label' => 'Occupancy %',
                    'data' => $trend['occupancy_percent'],
                    'borderColor' => 'rgb(245, 158, 11)',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.14)',
                    'fill' => true,
                    'tension' => 0.35,
                ],
            ],
            'labels' => $trend['labels'],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'layout' => [
                'padding' => [
                    'top' => 8,
                    'right' => 4,
                    'bottom' => 4,
                    'left' => 4,
                ],
            ],
            'plugins' => [
                'legend' => [
                    'display' => false,
                ],
            ],
            'scales' => [
                'x' => [
                    'ticks' => [
                        'maxRotation' => 0,
                        'minRotation' => 0,
                        'maxTicksLimit' => 7,
                    ],
                ],
                'y' => [
                    'beginAtZero' => true,
                    'max' => 100,
                    'ticks' => [
                        'maxTicksLimit' => 6,
                        'padding' => 8,
                    ],
                ],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
