<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Models\CommissionAccrual;
use App\Models\CostRecord;
use App\Models\Payment;
use App\Models\Reservation;
use Carbon\CarbonInterface;

class FinancialReportingService
{
    /** @return array<string, int|string> */
    public function summary(string $currency, CarbonInterface $startsAt, CarbonInterface $endsAt): array
    {
        $reservations = Reservation::query()
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
}
