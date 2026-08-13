<?php

namespace App\Services\Projections;

use App\Enums\DepositStatus;
use App\Enums\FolioLineType;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Models\CommissionAccrual;
use App\Models\CostRecord;
use App\Models\Deposit;
use App\Models\FolioLine;
use App\Models\Reservation;
use App\Services\FinancialReportingService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class FinanceProjectionService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly FinancialReportingService $reporting,
    ) {}

    /** @return array<string, mixed> */
    public function build(CarbonImmutable $start, CarbonImmutable $end, string $displayCurrency): array
    {
        $membership = $this->context->membership();
        $cacheKey = hash('sha256', implode('|', [
            $this->context->tenant()->id,
            $membership->property_id ?? 'all-properties',
            $membership->role->value,
            $start->toIso8601String(),
            $end->toIso8601String(),
            strtoupper($displayCurrency),
        ]));

        return Cache::remember(
            "finance-projection:{$cacheKey}",
            15,
            fn (): array => $this->buildFresh($start, $end, $displayCurrency),
        );
    }

    /** @return array<string, mixed> */
    private function buildFresh(CarbonImmutable $start, CarbonImmutable $end, string $displayCurrency): array
    {
        $timezone = $this->context->tenant()->timezone;
        if ($end->lessThanOrEqualTo($start) || $start->diffInDays($end) > 366) {
            $end = $start->addMonth();
        }
        $nativeCurrency = strtoupper($this->context->tenant()->currency);
        $displayCurrency = strtoupper($displayCurrency ?: $nativeCurrency);
        $propertyId = $this->context->membership()?->property_id;
        $bookableStatuses = [
            ReservationStatus::Confirmed,
            ReservationStatus::CheckedIn,
            ReservationStatus::CheckedOut,
        ];
        $reservations = Reservation::query()
            ->with([
                'program:id,name',
                'payments:id,reservation_id,status,currency,amount_minor,processed_at',
            ])
            ->when($propertyId, fn (Builder $query) => $query->where('property_id', $propertyId))
            ->whereIn('status', $bookableStatuses)
            ->where('starts_at', '>=', $start)
            ->where('starts_at', '<', $end)
            ->orderByDesc('starts_at')
            ->get();
        $reservationIds = $reservations->pluck('id');
        $costs = CostRecord::query()
            ->with(['program:id,name', 'reservation:id,program_id', 'reservation.program:id,name'])
            ->when($propertyId, fn (Builder $query) => $query->where(function (Builder $scope) use ($propertyId): void {
                $scope->whereHas('reservation', fn (Builder $reservation) => $reservation->where('property_id', $propertyId))
                    ->orWhereHas('program', fn (Builder $program) => $program->where('property_id', $propertyId));
            }))
            ->where('occurred_at', '>=', $start)
            ->where('occurred_at', '<', $end)
            ->get();
        $commissions = CommissionAccrual::query()
            ->with('reservation.program:id,name')
            ->whereIn('reservation_id', $reservationIds)
            ->get();
        $dualCurrency = $this->reporting->dualCurrencyReport($start, $end, $displayCurrency, $propertyId);
        $summaryTotals = $dualCurrency['conversion']['complete']
            ? $dualCurrency['consolidated_totals']
            : ($dualCurrency['raw_totals'][$displayCurrency] ?? null);
        $summaryAvailable = $summaryTotals !== null;
        $bookedRevenue = $summaryAvailable ? (int) $summaryTotals['booked_revenue_minor'] : null;
        $cashCollected = $summaryAvailable ? (int) $summaryTotals['cash_collected_minor'] : null;
        $receivables = $summaryAvailable ? (int) $summaryTotals['receivables_minor'] : null;
        $loadedCosts = $summaryAvailable ? (int) $summaryTotals['loaded_costs_minor'] : null;
        $commissionAccruals = $summaryAvailable ? (int) $summaryTotals['commission_accruals_minor'] : null;
        $margin = $summaryAvailable ? (int) $summaryTotals['margin_minor'] : null;
        $nativeReservations = $reservations->where('currency', $nativeCurrency)->values();
        $nativeCosts = $costs->where('currency', $nativeCurrency)->values();
        $nativeCommissions = $commissions->where('currency', $nativeCurrency)->values();
        $programs = $this->programs($nativeReservations, $nativeCosts, $nativeCommissions);
        $channels = $this->channels($nativeReservations, $nativeCommissions);
        $nativeBookedRevenue = (int) $nativeReservations->sum('total_minor');
        $nativeLoadedCosts = (int) $nativeCosts->sum('amount_minor');
        $nativeCommissionAccruals = (int) $nativeCommissions->sum('amount_minor');
        $nativeMargin = $nativeBookedRevenue - $nativeLoadedCosts - $nativeCommissionAccruals;
        $nativeProgramMargin = (int) collect($programs)->sum('margin_minor');
        $deposits = Deposit::query()
            ->when($propertyId, fn (Builder $query) => $query->whereHas('reservation', fn (Builder $reservation) => $reservation->where('property_id', $propertyId)))
            ->where('currency', $displayCurrency)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $due) => $due->where('due_at', '>=', $start)->where('due_at', '<', $end))
                ->orWhere(fn (Builder $paid) => $paid->where('paid_at', '>=', $start)->where('paid_at', '<', $end)))
            ->get();
        $folioLines = FolioLine::query()
            ->when($propertyId, fn (Builder $query) => $query->whereHas('reservation', fn (Builder $reservation) => $reservation->where('property_id', $propertyId)))
            ->where('currency', $displayCurrency)
            ->where('posted_at', '>=', $start)
            ->where('posted_at', '<', $end)
            ->get();

        return [
            'currency' => $displayCurrency,
            'native_currency' => $nativeCurrency,
            'display_currency' => $displayCurrency,
            'timezone' => $timezone,
            'period' => [
                'start' => $start->toIso8601String(),
                'end' => $end->toIso8601String(),
                'label' => $start->timezone($timezone)->format('F Y'),
            ],
            'summary' => [
                'available' => $summaryAvailable,
                'source' => $dualCurrency['conversion']['complete']
                    ? 'consolidated'
                    : ($summaryAvailable ? 'native_fallback' : 'unavailable'),
                'booked_revenue_minor' => $bookedRevenue,
                'cash_collected_minor' => $cashCollected,
                'receivables_minor' => $receivables,
                'loaded_costs_minor' => $loadedCosts,
                'commission_accruals_minor' => $commissionAccruals,
                'margin_minor' => $margin,
                'margin_percent' => $summaryAvailable && $bookedRevenue > 0 ? round(($margin / $bookedRevenue) * 100, 1) : null,
                'overdue_deposits_minor' => (int) $deposits
                    ->filter(fn (Deposit $deposit) => $deposit->status === DepositStatus::Due && $deposit->due_at?->isPast())
                    ->sum('amount_minor'),
                'collection_percent' => $summaryAvailable && $bookedRevenue > 0 ? round(($cashCollected / $bookedRevenue) * 100, 1) : null,
            ],
            'deposits' => [
                'due_count' => $deposits->where('status', DepositStatus::Due)->count(),
                'due_minor' => (int) $deposits->where('status', DepositStatus::Due)->sum('amount_minor'),
                'paid_count' => $deposits->where('status', DepositStatus::Paid)->count(),
                'paid_minor' => (int) $deposits->where('status', DepositStatus::Paid)->sum('amount_minor'),
                'overdue_count' => $deposits->filter(fn (Deposit $deposit) => $deposit->status === DepositStatus::Due && $deposit->due_at?->isPast())->count(),
            ],
            'folio' => [
                'charges_minor' => $this->folioAmount($folioLines, FolioLineType::Charge),
                'payments_minor' => abs($this->folioAmount($folioLines, FolioLineType::Payment)),
                'refunds_minor' => abs($this->folioAmount($folioLines, FolioLineType::Refund)),
                'adjustments_minor' => $this->folioAmount($folioLines, FolioLineType::Adjustment),
            ],
            'revenue_series' => $this->revenueSeries(
                $end->subSecond()->setTimezone($timezone),
                $end,
                $displayCurrency,
                $propertyId,
            ),
            'programs' => $programs,
            'programs_by_currency' => $this->programsByCurrency($reservations, $costs, $commissions),
            'channels' => $channels,
            'channels_by_currency' => $this->channelsByCurrency($reservations, $commissions),
            'reconciliation' => [
                'currency' => $nativeCurrency,
                'currency_policy' => 'native_currency_only',
                'formula' => 'booked_revenue_minor - loaded_costs_minor - commission_accruals_minor',
                'difference_minor' => $nativeMargin - ($nativeBookedRevenue - $nativeLoadedCosts - $nativeCommissionAccruals),
                'program_difference_minor' => $nativeMargin - $nativeProgramMargin,
                'is_balanced' => $nativeMargin === $nativeProgramMargin,
            ],
            'recent_folios' => $reservations->take(10)->map(fn (Reservation $reservation): array => [
                'reservation_id' => $reservation->id,
                'confirmation_number' => $reservation->confirmation_number,
                'status' => $reservation->status->value,
                'currency' => $reservation->currency,
                'total_minor' => $reservation->total_minor,
                'paid_minor' => $this->paid($reservation),
                'balance_minor' => $this->balance($reservation),
            ])->values(),
            'raw_totals' => $dualCurrency['raw_totals'],
            'consolidated_totals' => $dualCurrency['consolidated_totals'],
            'conversion' => $dualCurrency['conversion'],
        ];
    }

    /** @param Collection<int, FolioLine> $lines */
    private function folioAmount(Collection $lines, FolioLineType $type): int
    {
        return (int) $lines->where('type', $type)->sum('amount_minor');
    }

    /** @return list<array{label: string, value_minor: ?int, booked_minor: ?int, collected_minor: ?int, available: bool, native_totals: array<string, array<string, int|string>>}> */
    private function revenueSeries(CarbonImmutable $anchor, CarbonImmutable $reportEnd, string $currency, ?string $propertyId): array
    {
        return collect(range(6, 0))
            ->map(function (int $monthsAgo) use ($anchor, $reportEnd, $currency, $propertyId): array {
                $month = $anchor->startOfMonth()->subMonths($monthsAgo);
                $start = $month->startOfMonth()->utc();
                $monthEnd = $month->addMonth()->startOfMonth()->utc();
                $end = $monthEnd->lessThan($reportEnd) ? $monthEnd : $reportEnd;
                $report = $this->reporting->dualCurrencyReport($start, $end, $currency, $propertyId);
                $totals = $report['conversion']['complete']
                    ? $report['consolidated_totals']
                    : ($report['raw_totals'][$currency] ?? null);
                $available = $totals !== null;
                $booked = $available ? (int) $totals['booked_revenue_minor'] : null;
                $collected = $available ? (int) $totals['cash_collected_minor'] : null;

                return [
                    'label' => $month->format('M'),
                    'value_minor' => $booked,
                    'booked_minor' => $booked,
                    'collected_minor' => $collected,
                    'available' => $available,
                    'native_totals' => $report['raw_totals'],
                ];
            })
            ->all();
    }

    /** @param Collection<int, Reservation> $reservations @return list<array<string, mixed>> */
    private function channels(Collection $reservations, Collection $commissions): array
    {
        $commissionByReservation = $commissions
            ->groupBy('reservation_id')
            ->map(fn (Collection $items): int => (int) $items->sum('amount_minor'));

        return $reservations
            ->groupBy(fn (Reservation $reservation) => $reservation->source ?: 'Direct')
            ->map(function (Collection $channelReservations, string $channel) use ($commissionByReservation): array {
                $revenue = (int) $channelReservations->sum('total_minor');
                $collected = (int) $channelReservations->sum(fn (Reservation $reservation) => $this->paid($reservation));
                $commission = (int) $channelReservations->sum(
                    fn (Reservation $reservation) => $commissionByReservation->get($reservation->id, 0),
                );

                return [
                    'channel' => $channel,
                    'bookings' => $channelReservations->count(),
                    'revenue_minor' => $revenue,
                    'collected_minor' => $collected,
                    'commission_accruals_minor' => $commission,
                    'net_revenue_minor' => $revenue - $commission,
                    'collection_percent' => $revenue > 0 ? round(($collected / $revenue) * 100, 1) : 0.0,
                ];
            })
            ->sortByDesc('revenue_minor')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Reservation>  $reservations
     * @param  Collection<int, CostRecord>  $costs
     * @param  Collection<int, CommissionAccrual>  $commissions
     * @return list<array<string, int|string|null>>
     */
    private function programs(Collection $reservations, Collection $costs, Collection $commissions): array
    {
        $groups = [];
        $ensure = static function (?string $programId, ?string $programName) use (&$groups): string {
            $key = $programId ?? '__unallocated__';
            $groups[$key] ??= [
                'program_id' => $programId,
                'program' => $programName ?? 'Unallocated',
                'reservation_ids' => [],
                'revenue_minor' => 0,
                'loaded_costs_minor' => 0,
                'commission_accruals_minor' => 0,
            ];

            return $key;
        };

        foreach ($reservations as $reservation) {
            $key = $ensure($reservation->program_id, $reservation->program?->name);
            $groups[$key]['reservation_ids'][$reservation->id] = true;
            $groups[$key]['revenue_minor'] += $reservation->total_minor;
        }

        foreach ($costs as $cost) {
            $programId = data_get($cost, 'program_id') ?? data_get($cost, 'reservation.program_id');
            $programName = data_get($cost, 'program.name') ?? data_get($cost, 'reservation.program.name');
            $programId = is_string($programId) ? $programId : null;
            $programName = is_string($programName) ? $programName : null;
            $key = $ensure($programId, $programName);
            $groups[$key]['loaded_costs_minor'] += $cost->amount_minor;
        }

        foreach ($commissions as $commission) {
            $key = $ensure($commission->reservation->program_id, $commission->reservation->program?->name);
            $groups[$key]['commission_accruals_minor'] += $commission->amount_minor;
        }

        return collect($groups)
            ->map(static function (array $group): array {
                $group['bookings'] = count($group['reservation_ids']);
                unset($group['reservation_ids']);
                $group['margin_minor'] = $group['revenue_minor']
                    - $group['loaded_costs_minor']
                    - $group['commission_accruals_minor'];

                return $group;
            })
            ->sortByDesc('revenue_minor')
            ->values()
            ->all();
    }

    /** @return list<array<string, int|string|null>> */
    private function programsByCurrency(Collection $reservations, Collection $costs, Collection $commissions): array
    {
        return $reservations->pluck('currency')
            ->merge($costs->pluck('currency'))
            ->merge($commissions->pluck('currency'))
            ->map(fn ($currency): string => strtoupper((string) $currency))
            ->unique()
            ->sort()
            ->flatMap(function (string $currency) use ($reservations, $costs, $commissions): array {
                return array_map(
                    fn (array $row): array => [...$row, 'currency' => $currency],
                    $this->programs(
                        $reservations->where('currency', $currency)->values(),
                        $costs->where('currency', $currency)->values(),
                        $commissions->where('currency', $currency)->values(),
                    ),
                );
            })
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function channelsByCurrency(Collection $reservations, Collection $commissions): array
    {
        return $reservations->pluck('currency')
            ->merge($commissions->pluck('currency'))
            ->map(fn ($currency): string => strtoupper((string) $currency))
            ->unique()
            ->sort()
            ->flatMap(function (string $currency) use ($reservations, $commissions): array {
                return array_map(
                    fn (array $row): array => [...$row, 'currency' => $currency],
                    $this->channels(
                        $reservations->where('currency', $currency)->values(),
                        $commissions->where('currency', $currency)->values(),
                    ),
                );
            })
            ->values()
            ->all();
    }

    private function paid(Reservation $reservation): int
    {
        return (int) $reservation->payments
            ->where('status', PaymentStatus::Succeeded)
            ->where('currency', $reservation->currency)
            ->sum('amount_minor');
    }

    private function balance(Reservation $reservation): int
    {
        return max(0, $reservation->total_minor - $this->paid($reservation));
    }
}
