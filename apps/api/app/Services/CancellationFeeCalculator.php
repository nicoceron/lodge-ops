<?php

namespace App\Services;

use App\Models\Reservation;
use Carbon\CarbonImmutable;

final class CancellationFeeCalculator
{
    /** @return array{fee_minor:int,refundable_minor:int,days_before_arrival:int,tier:?array<string, mixed>} */
    public function calculate(Reservation $reservation, mixed $effectiveAt = null): array
    {
        $effective = CarbonImmutable::parse($effectiveAt ?? now());
        $days = max(0, (int) $effective->startOfDay()->diffInDays($reservation->starts_at->startOfDay(), false));
        $tiers = collect($reservation->cancellation_policy_snapshot['tiers'] ?? [])
            ->map(fn (array $tier): array => [
                'days_before_arrival' => (int) ($tier['days_before_arrival'] ?? 0),
                'retained_basis_points' => (int) ($tier['retained_basis_points'] ?? 0),
                'minimum_fee_minor' => (int) ($tier['minimum_fee_minor'] ?? 0),
            ])
            ->sortBy('days_before_arrival')
            ->values();
        $tier = $tiers->first(fn (array $candidate): bool => $days <= $candidate['days_before_arrival']);
        $fee = $tier === null
            ? 0
            : max(
                intdiv(($reservation->total_minor * $tier['retained_basis_points']) + 9999, 10000),
                $tier['minimum_fee_minor'],
            );
        $fee = min($reservation->total_minor, max(0, $fee));

        return [
            'fee_minor' => $fee,
            'refundable_minor' => max(0, $reservation->total_minor - $fee),
            'days_before_arrival' => $days,
            'tier' => $tier,
        ];
    }
}
