<?php

namespace App\Filament\Widgets;

use App\Services\Projections\FinanceProjectionService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

class FinanceRevenueTrend extends ChartWidget
{
    public string $start;

    public string $end;

    public string $displayCurrency;

    protected static bool $isDiscovered = false;

    public static function canView(): bool
    {
        return app(TenantContext::class)->membership()?->role?->canViewFinance() === true;
    }

    protected ?string $heading = 'Revenue vs cash collected';

    protected ?string $description = 'Seven-month trend ending in the selected period, in the lodge currency.';

    protected ?string $pollingInterval = null;

    protected function getData(): array
    {
        $series = $this->projection()['revenue_series'];

        return [
            'datasets' => [
                [
                    'label' => 'Booked revenue',
                    'data' => array_map(fn (array $month): float => $month['booked_minor'] / 100, $series),
                    'borderColor' => 'rgb(59, 130, 246)',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.14)',
                    'fill' => true,
                    'tension' => 0.35,
                ],
                [
                    'label' => 'Cash collected',
                    'data' => array_map(fn (array $month): float => $month['collected_minor'] / 100, $series),
                    'borderColor' => 'rgb(34, 197, 94)',
                    'backgroundColor' => 'rgba(34, 197, 94, 0.08)',
                    'fill' => true,
                    'tension' => 0.35,
                ],
            ],
            'labels' => array_column($series, 'label'),
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

    /** @return array<string, mixed> */
    private function projection(): array
    {
        $tenant = app(TenantContext::class)->tenant();
        $fallback = CarbonImmutable::now($tenant->timezone);

        try {
            $start = CarbonImmutable::parse($this->start, $tenant->timezone)->startOfDay();
        } catch (\Throwable) {
            $start = $fallback->startOfMonth();
        }

        try {
            $end = CarbonImmutable::parse($this->end, $tenant->timezone)->addDay()->startOfDay();
        } catch (\Throwable) {
            $end = $fallback->addMonth()->startOfMonth();
        }

        return app(FinanceProjectionService::class)->build(
            $start->utc(),
            $end->utc(),
            $this->displayCurrency,
        );
    }
}
