<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Models\CommissionAccrual;
use App\Models\CostRecord;
use App\Models\ExchangeRate;
use App\Models\Payment;
use App\Models\Reservation;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

class FinancialReportingService
{
    /** @return array<string, int|string> */
    public function summary(string $currency, CarbonInterface $startsAt, CarbonInterface $endsAt, ?string $propertyId = null): array
    {
        $reservations = Reservation::query()
            ->when($propertyId, fn (Builder $query) => $query->where('property_id', $propertyId))
            ->where('currency', $currency)
            ->whereNotIn('status', [ReservationStatus::Cancelled, ReservationStatus::NoShow])
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt);

        $booked = (int) (clone $reservations)->sum('total_minor');
        $reservationIds = (clone $reservations)->pluck('id');
        $collected = (int) Payment::query()
            ->where('currency', $currency)
            ->where('status', PaymentStatus::Succeeded)
            ->whereIn('reservation_id', $reservationIds)
            ->sum('amount_minor');
        $costs = (int) CostRecord::query()
            ->when($propertyId, fn (Builder $query) => $query->where(function (Builder $scope) use ($propertyId): void {
                $scope->whereHas('reservation', fn (Builder $reservation) => $reservation->where('property_id', $propertyId))
                    ->orWhereHas('program', fn (Builder $program) => $program->where('property_id', $propertyId));
            }))
            ->where('currency', $currency)
            ->where('occurred_at', '>=', $startsAt)
            ->where('occurred_at', '<', $endsAt)
            ->sum('amount_minor');
        $commissions = (int) CommissionAccrual::query()
            ->where('currency', $currency)
            ->whereIn('reservation_id', $reservationIds)
            ->sum('amount_minor');

        return [
            'currency' => $currency,
            'booked_minor' => $booked,
            'collected_minor' => $collected,
            'receivable_minor' => max(0, $booked - $collected),
            'cost_minor' => $costs,
            'commission_minor' => $commissions,
            'margin_minor' => $booked - $costs - $commissions,
        ];
    }

    /**
     * Return native totals for every currency and an optional, fully-audited
     * consolidation into the selected display currency.
     *
     * A missing effective-date rate never causes a silent conversion: native
     * totals remain available, while consolidated totals become null and the
     * missing rate is exposed to the caller.
     *
     * @return array{
     *     raw_totals: array<string, array<string, int|string>>,
     *     consolidated_totals: array<string, int|string|null>,
     *     conversion: array{display_currency: string, complete: bool, policy: string, rates: list<array<string, mixed>>, missing_rates: list<array<string, mixed>>}
     * }
     */
    public function dualCurrencyReport(
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        string $displayCurrency,
        ?string $propertyId = null,
    ): array {
        $displayCurrency = strtoupper($displayCurrency);
        $bookableStatuses = [
            ReservationStatus::Confirmed,
            ReservationStatus::CheckedIn,
            ReservationStatus::CheckedOut,
        ];
        $reservations = Reservation::query()
            ->with('property:id')
            ->when($propertyId, fn (Builder $query) => $query->where('property_id', $propertyId))
            ->whereIn('status', $bookableStatuses)
            ->where('starts_at', '>=', $startsAt)
            ->where('starts_at', '<', $endsAt)
            ->get(['id', 'property_id', 'currency', 'total_minor', 'starts_at']);
        $reservationIds = $reservations->pluck('id');
        $payments = Payment::query()
            ->with('reservation:id,property_id')
            ->when($propertyId, fn (Builder $query) => $query->whereHas('reservation', fn (Builder $reservation) => $reservation->where('property_id', $propertyId)))
            ->where('status', PaymentStatus::Succeeded)
            ->where('processed_at', '>=', $startsAt)
            ->where('processed_at', '<', $endsAt)
            ->get(['id', 'reservation_id', 'currency', 'amount_minor', 'processed_at']);
        $costs = CostRecord::query()
            ->with(['reservation:id,property_id', 'program:id,property_id'])
            ->when($propertyId, fn (Builder $query) => $query->where(function (Builder $scope) use ($propertyId): void {
                $scope->whereHas('reservation', fn (Builder $reservation) => $reservation->where('property_id', $propertyId))
                    ->orWhereHas('program', fn (Builder $program) => $program->where('property_id', $propertyId));
            }))
            ->where('occurred_at', '>=', $startsAt)
            ->where('occurred_at', '<', $endsAt)
            ->get(['id', 'reservation_id', 'program_id', 'currency', 'amount_minor', 'occurred_at']);
        $commissions = CommissionAccrual::query()
            ->with('reservation:id,property_id,starts_at')
            ->whereIn('reservation_id', $reservationIds)
            ->get(['id', 'reservation_id', 'currency', 'amount_minor', 'created_at']);

        $raw = [];
        $converted = [
            'booked_revenue_minor' => 0,
            'cash_collected_minor' => 0,
            'loaded_costs_minor' => 0,
            'commission_accruals_minor' => 0,
        ];
        $rates = [];
        $missingRates = [];
        $exchangeRates = app(ExchangeRateService::class);

        $ensureRaw = static function (string $currency) use (&$raw): void {
            $raw[$currency] ??= [
                'currency' => $currency,
                'booked_revenue_minor' => 0,
                'cash_collected_minor' => 0,
                'receivables_minor' => 0,
                'loaded_costs_minor' => 0,
                'commission_accruals_minor' => 0,
                'margin_minor' => 0,
                // Compatibility aliases used by the older financial-summary API.
                'booked_minor' => 0,
                'collected_minor' => 0,
                'receivable_minor' => 0,
                'cost_minor' => 0,
                'commission_minor' => 0,
            ];
        };

        $addConverted = function (
            string $metric,
            string $fromCurrency,
            int $amountMinor,
            DateTimeInterface $effectiveAt,
            ?string $recordPropertyId,
        ) use (&$converted, &$missingRates, &$rates, $displayCurrency, $exchangeRates): void {
            if ($amountMinor === 0) {
                return;
            }

            $conversion = $exchangeRates->conversion(
                $fromCurrency,
                $displayCurrency,
                $effectiveAt,
                $recordPropertyId,
            );
            if ($conversion === null) {
                $key = implode('|', [
                    $fromCurrency,
                    $displayCurrency,
                    $recordPropertyId ?? 'tenant',
                    $effectiveAt->format(DateTimeInterface::ATOM),
                ]);
                $missingRates[$key] ??= [
                    'from_currency' => $fromCurrency,
                    'to_currency' => $displayCurrency,
                    'property_id' => $recordPropertyId,
                    'effective_at' => $effectiveAt->format(DateTimeInterface::ATOM),
                    'status' => 'missing_rate',
                ];

                return;
            }

            $snapshotId = $conversion['snapshot'] instanceof ExchangeRate
                ? $conversion['snapshot']->id
                : 'identity';
            $rateKey = implode('|', [
                $conversion['from_currency'],
                $conversion['to_currency'],
                $snapshotId,
                $conversion['direction'],
            ]);
            $rates[$rateKey] ??= [
                'from_currency' => $conversion['from_currency'],
                'to_currency' => $conversion['to_currency'],
                'rate' => $conversion['rate'],
                'source' => $conversion['source'],
                'effective_at' => $conversion['effective_at'],
                'property_id' => $conversion['property_id'],
                'direction' => $conversion['direction'],
                'status' => 'applied',
            ];
            $converted[$metric] += $exchangeRates->convertMinorForConversion($amountMinor, $conversion);
        };

        foreach ($reservations as $reservation) {
            /** @var Reservation $reservation */
            $currency = strtoupper($reservation->currency);
            $amount = (int) $reservation->total_minor;
            $ensureRaw($currency);
            $raw[$currency]['booked_revenue_minor'] += $amount;
            $raw[$currency]['booked_minor'] += $amount;
            $addConverted('booked_revenue_minor', $currency, $amount, $reservation->starts_at, $reservation->property_id);
        }

        foreach ($payments as $payment) {
            /** @var Payment $payment */
            $currency = strtoupper($payment->currency);
            $amount = (int) $payment->amount_minor;
            $ensureRaw($currency);
            $raw[$currency]['cash_collected_minor'] += $amount;
            $raw[$currency]['collected_minor'] += $amount;
            $addConverted(
                'cash_collected_minor',
                $currency,
                $amount,
                $payment->processed_at ?? $startsAt,
                $payment->reservation->property_id,
            );
        }

        foreach ($costs as $cost) {
            /** @var CostRecord $cost */
            $currency = strtoupper($cost->currency);
            $amount = (int) $cost->amount_minor;
            $recordPropertyId = data_get($cost, 'reservation.property_id')
                ?? data_get($cost, 'program.property_id');
            $recordPropertyId = is_string($recordPropertyId) ? $recordPropertyId : null;
            $ensureRaw($currency);
            $raw[$currency]['loaded_costs_minor'] += $amount;
            $raw[$currency]['cost_minor'] += $amount;
            $addConverted(
                'loaded_costs_minor',
                $currency,
                $amount,
                $cost->occurred_at,
                $recordPropertyId,
            );
        }

        foreach ($commissions as $commission) {
            /** @var CommissionAccrual $commission */
            $currency = strtoupper($commission->currency);
            $amount = (int) $commission->amount_minor;
            $effectiveAt = $commission->created_at ?? $commission->reservation->starts_at ?? $startsAt;
            $ensureRaw($currency);
            $raw[$currency]['commission_accruals_minor'] += $amount;
            $raw[$currency]['commission_minor'] += $amount;
            $addConverted(
                'commission_accruals_minor',
                $currency,
                $amount,
                $effectiveAt,
                $commission->reservation->property_id,
            );
        }

        foreach ($raw as &$totals) {
            $totals['receivables_minor'] = max(0, $totals['booked_revenue_minor'] - $totals['cash_collected_minor']);
            $totals['receivable_minor'] = $totals['receivables_minor'];
            $totals['margin_minor'] = $totals['booked_revenue_minor']
                - $totals['loaded_costs_minor']
                - $totals['commission_accruals_minor'];
        }
        unset($totals);

        $complete = $missingRates === [];
        $consolidated = [
            'currency' => $displayCurrency,
            'booked_revenue_minor' => $complete ? $converted['booked_revenue_minor'] : null,
            'cash_collected_minor' => $complete ? $converted['cash_collected_minor'] : null,
            'receivables_minor' => $complete ? max(0, $converted['booked_revenue_minor'] - $converted['cash_collected_minor']) : null,
            'loaded_costs_minor' => $complete ? $converted['loaded_costs_minor'] : null,
            'commission_accruals_minor' => $complete ? $converted['commission_accruals_minor'] : null,
            'margin_minor' => $complete
                ? $converted['booked_revenue_minor'] - $converted['loaded_costs_minor'] - $converted['commission_accruals_minor']
                : null,
        ];

        return [
            'raw_totals' => $raw,
            'consolidated_totals' => $consolidated,
            'conversion' => [
                'display_currency' => $displayCurrency,
                'complete' => $complete,
                'policy' => 'effective_rate_required',
                'rates' => array_values($rates),
                'missing_rates' => array_values($missingRates),
            ],
        ];
    }
}
