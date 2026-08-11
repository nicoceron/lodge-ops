<?php

namespace App\Filament\Widgets;

use App\Enums\MembershipRole;
use App\Services\Projections\DashboardProjectionService;
use App\Support\Tenancy\TenantContext;
use Filament\Widgets\ChartWidget;

class LodgeFlowTrend extends ChartWidget
{
    protected ?string $heading = 'Arrivals and departures';

    protected ?string $description = 'Daily guest flow across the current 14-day operating window.';

    protected static ?int $sort = 2;

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
                    'label' => 'Arrivals',
                    'data' => $trend['arrivals'],
                    'borderColor' => 'rgb(59, 130, 246)',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.14)',
                    'fill' => true,
                    'tension' => 0.35,
                ],
                [
                    'label' => 'Departures',
                    'data' => $trend['departures'],
                    'borderColor' => 'rgb(148, 163, 184)',
                    'borderDash' => [6, 4],
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
                    'labels' => [
                        'padding' => 16,
                    ],
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
                    'ticks' => [
                        'precision' => 0,
                        'maxTicksLimit' => 5,
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
