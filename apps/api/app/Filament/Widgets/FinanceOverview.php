<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Deposits\DepositResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Services\MoneyFormatter;
use App\Services\Projections\FinanceProjectionService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FinanceOverview extends StatsOverviewWidget
{
    public string $start;

    public string $end;

    public string $displayCurrency;

    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return app(TenantContext::class)->membership()?->role?->canViewFinance() === true;
    }

    protected ?string $heading = 'Financial pulse';

    protected ?string $description = 'Four decision metrics for the selected period. Open a card to review its source records.';

    protected function getStats(): array
    {
        $projection = $this->projection();
        $summary = $projection['summary'];
        $currency = $projection['currency'];
        $tenant = app(TenantContext::class)->tenant();
        $money = app(MoneyFormatter::class);
        $format = fn (?int $amount): string => $amount === null
            ? 'Unavailable'
            : $money->formatMinor($amount, $currency, $tenant->locale ?: app()->getLocale());
        $bookedTrend = array_map(fn (array $month): float => ($month['booked_minor'] ?? 0) / 100, $projection['revenue_series']);
        $collectedTrend = array_map(fn (array $month): float => ($month['collected_minor'] ?? 0) / 100, $projection['revenue_series']);
        $dueDeposits = $projection['deposits']['due_count'];
        $overdueDeposits = $projection['deposits']['overdue_count'];

        return [
            Stat::make('Booked revenue', $format($summary['booked_revenue_minor']))
                ->description($projection['period']['label'].' · '.$currency)
                ->descriptionIcon('heroicon-m-calendar-days')
                ->chart($bookedTrend)
                ->color('info')
                ->url(ReservationResource::canViewAny() ? ReservationResource::getUrl() : null),
            Stat::make('Cash collected', $format($summary['cash_collected_minor']))
                ->description($summary['available'] ? $summary['collection_percent'].'% cash collected vs booked arrivals' : 'Missing effective FX rates')
                ->descriptionIcon('heroicon-m-banknotes')
                ->chart($collectedTrend)
                ->color(($summary['cash_collected_minor'] ?? 0) > 0 ? 'success' : 'gray')
                ->url(PaymentResource::getUrl()),
            Stat::make('Receivables', $format($summary['receivables_minor']))
                ->description("{$dueDeposits} due · {$overdueDeposits} overdue")
                ->descriptionIcon($overdueDeposits > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($overdueDeposits > 0 ? 'danger' : 'success')
                ->url(DepositResource::getUrl()),
            Stat::make('Gross margin', $format($summary['margin_minor']))
                ->description($summary['available'] ? $summary['margin_percent'].'% margin after costs' : 'Missing effective FX rates')
                ->descriptionIcon(($summary['margin_minor'] ?? 0) >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color(! $summary['available'] ? 'warning' : (($summary['margin_minor'] ?? 0) >= 0 ? 'success' : 'danger')),
        ];
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
