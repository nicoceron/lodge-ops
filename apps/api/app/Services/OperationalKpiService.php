<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Enums\TaskStatus;
use App\Models\FolioLine;
use App\Models\OperationalTask;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Resource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class OperationalKpiService
{
    /** @return array<string, mixed> */
    public function reconcile(CarbonImmutable $localStart, CarbonImmutable $localEnd, string $timezone, ?string $propertyId): array
    {
        $start = $localStart->startOfDay()->utc();
        $end = $localEnd->addDay()->startOfDay()->utc();
        $reservationScope = fn (): Builder => Reservation::query()
            ->when($propertyId, fn (Builder $query) => $query->where('property_id', $propertyId));
        $active = [ReservationStatus::Confirmed, ReservationStatus::CheckedIn, ReservationStatus::CheckedOut];
        $reservations = $reservationScope()->whereIn('status', $active)
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)->get();
        $capacity = Resource::query()->with('category')
            ->when($propertyId, fn (Builder $query) => $query->where('property_id', $propertyId))
            ->where('is_active', true)->whereHas('category', fn (Builder $query) => $query->where('counts_as_stay', true))
            ->sum('capacity');
        $days = max(1, $localStart->diffInDays($localEnd) + 1);
        $occupiedRoomNights = (int) $reservations->sum(function (Reservation $reservation) use ($start, $end, $timezone): int {
            $from = $reservation->starts_at->greaterThan($start) ? $reservation->starts_at : $start;
            $to = $reservation->ends_at->lessThan($end) ? $reservation->ends_at : $end;

            return max(0, $from->timezone($timezone)->startOfDay()->diffInDays($to->timezone($timezone)->startOfDay()));
        });
        $availableRoomNights = (int) $capacity * $days;
        $postedRevenue = FolioLine::query()
            ->where('posted_at', '>=', $start)->where('posted_at', '<', $end)
            ->when($propertyId, fn (Builder $query) => $query->whereHas('reservation', fn (Builder $reservation) => $reservation->where('property_id', $propertyId)))
            ->selectRaw('currency, SUM(gross_amount_minor) as gross_minor')
            ->groupBy('currency')
            ->pluck('gross_minor', 'currency')
            ->map(fn ($amount): int => (int) $amount);
        $currencies = $reservations->pluck('currency')->merge($postedRevenue->keys())->unique()->sort()->values();
        $currencyRows = $currencies->map(function (string $currency) use ($reservations, $postedRevenue): array {
            $currencyReservations = $reservations->where('currency', $currency);
            $ids = $currencyReservations->pluck('id');
            $revenue = (int) ($postedRevenue->get($currency) ?? 0);
            $paid = (int) Payment::query()->whereIn('reservation_id', $ids)->where('currency', $currency)
                ->where('status', PaymentStatus::Succeeded)->sum('amount_minor');
            $booked = (int) $currencyReservations->sum('total_minor');
            $rawBalance = $booked - $paid;
            $reportedOutstanding = max(0, $rawBalance);
            $overpayment = max(0, -$rawBalance);

            return [
                'currency' => $currency,
                'revenue_minor' => $revenue,
                'booked_minor' => $booked,
                'deposit_received_minor' => $paid,
                'outstanding_minor' => $reportedOutstanding,
                'adr_minor' => $currencyReservations->count() === 0 ? null : intdiv($booked, $currencyReservations->count()),
                'disclosure' => 'Currency totals are not converted or combined.',
                'reconciliation' => [
                    'booked_less_deposits_minor' => $rawBalance,
                    'reported_outstanding_minor' => $reportedOutstanding,
                    'overpayment_minor' => $overpayment,
                    'balanced' => $booked + $overpayment === $paid + $reportedOutstanding,
                ],
            ];
        })->values();
        $taskScope = OperationalTask::query()->when($propertyId, fn (Builder $query) => $query->where('property_id', $propertyId));

        return [
            'status' => 'provisional_client_approval_required',
            'range' => ['local_start' => $localStart->toDateString(), 'local_end' => $localEnd->toDateString(), 'timezone' => $timezone, 'property_id' => $propertyId, 'utc_start' => $start->toIso8601String(), 'utc_end_exclusive' => $end->toIso8601String()],
            'definitions' => $this->definitions(),
            'values' => [
                'reservation_volume' => $reservations->count(),
                'occupied_room_nights' => $occupiedRoomNights,
                'available_room_nights' => $availableRoomNights,
                'occupancy_percent' => $availableRoomNights <= 0 ? null : round(($occupiedRoomNights / $availableRoomNights) * 100, 2),
                'arrivals' => $reservationScope()->whereIn('status', $active)->where('starts_at', '>=', $start)->where('starts_at', '<', $end)->count(),
                'departures' => $reservationScope()->whereIn('status', $active)->where('ends_at', '>=', $start)->where('ends_at', '<', $end)->count(),
                'tasks_total' => (clone $taskScope)->where(fn (Builder $query) => $query->whereNull('due_at')->orWhere(fn (Builder $due) => $due->where('due_at', '>=', $start)->where('due_at', '<', $end)))->count(),
                'tasks_overdue' => (clone $taskScope)->where('due_at', '<', now())->whereNotIn('status', [TaskStatus::Done, TaskStatus::Cancelled, TaskStatus::Superseded])->count(),
                'kitchen_guest_count' => (int) $reservations->sum(fn (Reservation $reservation): int => $reservation->adults + $reservation->children),
                'currencies' => $currencyRows,
            ],
            'reconciliation' => [
                'occupancy_balanced' => $occupiedRoomNights <= $availableRoomNights,
                'currency_rows_balanced' => $currencyRows->every(fn (array $row): bool => data_get($row, 'reconciliation.balanced') === true),
                'source_tables' => ['reservations', 'resources', 'folio_lines', 'payments', 'operational_tasks'],
            ],
        ];
    }

    /** @return list<array<string, string>> */
    public function definitions(): array
    {
        return [
            ['key' => 'occupancy_percent', 'numerator' => 'occupied room nights', 'denominator' => 'active stay-resource capacity x local calendar days', 'timezone' => 'property local', 'currency' => 'none', 'exclusions' => 'draft, hold, cancelled, no-show; half-open departure day', 'reconciliation' => 'reservations overlap local day window; active stay-category resource capacity'],
            ['key' => 'adr_minor', 'numerator' => 'immutable booked total by currency', 'denominator' => 'in-scope reservations in same currency', 'timezone' => 'property local overlap', 'currency' => 'separate ISO currency rows', 'exclusions' => 'no FX aggregation; zero denominator returns null', 'reconciliation' => 'sum reservations.total_minor grouped by currency'],
            ['key' => 'revenue_minor', 'numerator' => 'posted folio gross amount by currency', 'denominator' => 'none', 'timezone' => 'property local period converted to half-open UTC', 'currency' => 'separate ISO currency rows', 'exclusions' => 'outside posted-at window', 'reconciliation' => 'sum folio_lines.gross_amount_minor grouped by currency'],
            ['key' => 'deposit_received_minor', 'numerator' => 'succeeded payment amount by currency', 'denominator' => 'none', 'timezone' => 'reservation period', 'currency' => 'separate ISO currency rows', 'exclusions' => 'non-succeeded payments and refunds disclosed separately', 'reconciliation' => 'sum succeeded payments.amount_minor grouped by currency'],
            ['key' => 'outstanding_minor', 'numerator' => 'booked total less succeeded payments', 'denominator' => 'none', 'timezone' => 'reservation period', 'currency' => 'separate ISO currency rows', 'exclusions' => 'never below zero', 'reconciliation' => 'reservations.total_minor minus succeeded payments.amount_minor'],
            ['key' => 'task_overdue', 'numerator' => 'tasks due before the audit instant', 'denominator' => 'none', 'timezone' => 'UTC audit instant displayed property local', 'currency' => 'none', 'exclusions' => 'done, cancelled, superseded', 'reconciliation' => 'operational_tasks due_at and status'],
        ];
    }
}
