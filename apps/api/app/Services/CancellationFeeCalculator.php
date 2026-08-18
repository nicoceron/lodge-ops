<?php

namespace App\Services;

use App\Models\Reservation;
use Carbon\CarbonImmutable;
use DateTimeInterface;

final class CancellationFeeCalculator
{
    /** @return array{fee_minor:int,refundable_minor:int,days_before_arrival:int,property_timezone:string,effective_at_utc:string,effective_local_date:string,arrival_local_date:string,tier:?array<string, mixed>} */
    public function calculate(Reservation $reservation, mixed $effectiveAt = null): array
    {
        $timezone = $reservation->property()->value('timezone') ?: config('app.timezone', 'UTC');
        $effective = $effectiveAt instanceof DateTimeInterface
            ? CarbonImmutable::instance($effectiveAt)
            : CarbonImmutable::parse($effectiveAt ?? now());
        $effectiveLocalDay = $effective->setTimezone($timezone)->startOfDay();
        $arrivalLocalDay = $reservation->starts_at->setTimezone($timezone)->startOfDay();
        $days = max(0, (int) $effectiveLocalDay->diffInDays($arrivalLocalDay, false));
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
            'property_timezone' => $timezone,
            'effective_at_utc' => $effective->utc()->toIso8601String(),
            'effective_local_date' => $effectiveLocalDay->toDateString(),
            'arrival_local_date' => $arrivalLocalDay->toDateString(),
            'tier' => $tier,
        ];
    }
}
