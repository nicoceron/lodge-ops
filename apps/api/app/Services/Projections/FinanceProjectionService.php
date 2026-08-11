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
use App\Models\Payment;
use App\Models\Reservation;
use App\Services\FinancialReportingService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class FinanceProjectionService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly FinancialReportingService $reporting,
    ) {}

    /** @return array<string, mixed> */
    public function build(CarbonImmutable $start, CarbonImmutable $end, string $displayCurrency): array
    {
        $timezone = $this->context->tenant()->timezone;
        $now = CarbonImmutable::now($timezone);
        if ($end->lessThanOrEqualTo($start)) {
            $end = $start->addMonth();
        }
        $currency = strtoupper($this->context->tenant()->currency);
        $displayCurrency = strtoupper($displayCurrency ?: $currency);
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
            ->where('currency', $currency)
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
            ->where('currency', $currency)
            ->where('occurred_at', '>=', $start)
            ->where('occurred_at', '<', $end)
            ->get();
        $commissions = CommissionAccrual::query()
            ->with('reservation.program:id,name')
            ->where('currency', $currency)
            ->whereIn('reservation_id', $reservationIds)
            ->get();
        $bookedRevenue = (int) $reservations->sum('total_minor');
        $cashCollected = (int) Payment::query()
            ->when($propertyId, fn (Builder $query) => $query->whereHas('reservation', fn (Builder $reservation) => $reservation->where('property_id', $propertyId)))
            ->where('currency', $currency)
            ->where('status', PaymentStatus::Succeeded)
            ->where('processed_at', '>=', $start)
            ->where('processed_at', '<', $end)
            ->sum('amount_minor');
        $receivables = (int) $reservations->sum(fn (Reservation $reservation) => $this->balance($reservation, $currency));
        $loadedCosts = (int) $costs->sum('amount_minor');
        $commissionAccruals = (int) $commissions->sum('amount_minor');
        $margin = $bookedRevenue - $loadedCosts - $commissionAccruals;
        $programs = $this->programs($reservations, $costs, $commissions);
        $programMargin = (int) collect($programs)->sum('margin_minor');
        $deposits = Deposit::query()
            ->when($propertyId, fn (Builder $query) => $query->whereHas('reservation', fn (Builder $reservation) => $reservation->where('property_id', $propertyId)))
            ->where('currency', $currency)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $due) => $due->where('due_at', '>=', $start)->where('due_at', '<', $end))
                ->orWhere(fn (Builder $paid) => $paid->where('paid_at', '>=', $start)->where('paid_at', '<', $end)))
            ->get();
        $folioLines = FolioLine::query()
            ->when($propertyId, fn (Builder $query) => $query->whereHas('reservation', fn (Builder $reservation) => $reservation->where('property_id', $propertyId)))
            ->where('currency', $currency)
            ->where('posted_at', '>=', $start)
            ->where('posted_at', '<', $end)
            ->get();
        $dualCurrency = $this->reporting->dualCurrencyReport($start, $end, $displayCurrency, $propertyId);

        return [
            'currency' => $currency,
            'display_currency' => $displayCurrency,
            'timezone' => $timezone,
            'period' => [
                'start' => $start->toIso8601String(),
                'end' => $end->toIso8601String(),
                'label' => $start->timezone($timezone)->format('F Y'),
            ],
            'summary' => [
                'booked_revenue_minor' => $bookedRevenue,
                'cash_collected_minor' => $cashCollected,
                'receivables_minor' => $receivables,
                'loaded_costs_minor' => $loadedCosts,
                'commission_accruals_minor' => $commissionAccruals,
                'margin_minor' => $margin,
                'margin_percent' => $bookedRevenue > 0 ? round(($margin / $bookedRevenue) * 100, 1) : 0.0,
                'overdue_deposits_minor' => (int) $deposits
                    ->filter(fn (Deposit $deposit) => $deposit->status === DepositStatus::Due && $deposit->due_at?->isPast())
                    ->sum('amount_minor'),
                'collection_percent' => $bookedRevenue > 0 ? round(($cashCollected / $bookedRevenue) * 100, 1) : 0.0,
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
            'revenue_series' => $this->revenueSeries($now, $bookableStatuses, $currency, $propertyId),
            'programs' => $programs,
            'channels' => $this->channels($reservations, $commissions, $currency),
            'reconciliation' => [
                'currency' => $currency,
                'currency_policy' => 'native_currency_only',
                'formula' => 'booked_revenue_minor - loaded_costs_minor - commission_accruals_minor',
                'difference_minor' => $margin - ($bookedRevenue - $loadedCosts - $commissionAccruals),
                'program_difference_minor' => $margin - $programMargin,
                'is_balanced' => $margin === $programMargin,
            ],
            'recent_folios' => $reservations->take(10)->map(fn (Reservation $reservation): array => [
                'reservation_id' => $reservation->id,
                'confirmation_number' => $reservation->confirmation_number,
                'status' => $reservation->status->value,
                'total_minor' => $reservation->total_minor,
                'paid_minor' => $this->paid($reservation, $currency),
                'balance_minor' => $this->balance($reservation, $currency),
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

    /** @param list<ReservationStatus> $statuses @return list<array{label: string, value_minor: int}> */
    private function revenueSeries(CarbonImmutable $now, array $statuses, string $currency, ?string $propertyId): array
    {
        return collect(range(6, 0))
            ->map(function (int $monthsAgo) use ($now, $statuses, $currency, $propertyId): array {
                $month = $now->subMonths($monthsAgo);
                $start = $month->startOfMonth()->utc();
                $end = $month->addMonth()->startOfMonth()->utc();

                return [
                    'label' => $month->format('M'),
                    'value_minor' => (int) Reservation::query()
                        ->when($propertyId, fn (Builder $query) => $query->where('property_id', $propertyId))
                        ->where('currency', $currency)
                        ->whereIn('status', $statuses)
                        ->where('starts_at', '>=', $start)
                        ->where('starts_at', '<', $end)
                        ->sum('total_minor'),
                ];
            })
            ->all();
    }

    /** @param Collection<int, Reservation> $reservations @return list<array<string, mixed>> */
    private function channels(Collection $reservations, Collection $commissions, string $currency): array
    {
        $commissionByReservation = $commissions
            ->groupBy('reservation_id')
            ->map(fn (Collection $items): int => (int) $items->sum('amount_minor'));

        return $reservations
            ->groupBy(fn (Reservation $reservation) => $reservation->source ?: 'Direct')
            ->map(function (Collection $channelReservations, string $channel) use ($commissionByReservation, $currency): array {
                $revenue = (int) $channelReservations->sum('total_minor');
                $collected = (int) $channelReservations->sum(fn (Reservation $reservation) => $this->paid($reservation, $currency));
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

    private function paid(Reservation $reservation, string $currency): int
    {
        return (int) $reservation->payments
            ->where('status', PaymentStatus::Succeeded)
            ->where('currency', $currency)
            ->sum('amount_minor');
    }

    private function balance(Reservation $reservation, string $currency): int
    {
        return max(0, $reservation->total_minor - $this->paid($reservation, $currency));
    }
}
