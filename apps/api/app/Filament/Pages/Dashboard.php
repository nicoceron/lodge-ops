<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\LodgeCommandCenter;
use App\Filament\Widgets\LodgeFlowTrend;
use App\Filament\Widgets\LodgeOccupancyTrend;
use App\Filament\Widgets\LodgeReadinessOverview;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    /**
     * Keep the operational dashboard explicit so its presentation order does not
     * change when other panel widgets are added for finance or future modules.
     * Visibility is still enforced by each widget's canView() method.
     *
     * @return array<class-string>
     */
    public function getWidgets(): array
    {
        return [
            LodgeReadinessOverview::class,
            LodgeFlowTrend::class,
            LodgeOccupancyTrend::class,
            LodgeCommandCenter::class,
        ];
    }

    /**
     * Use two columns for the working dashboard, with a four-column canvas on
     * wide screens so the full-width stats widget can use its native card grid.
     *
     * @return array<string, int>
     */
    public function getColumns(): int|array
    {
        return [
            'md' => 2,
            '2xl' => 4,
        ];
    }
}
