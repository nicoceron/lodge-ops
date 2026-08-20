<?php

namespace App\Services\Reports;

use App\Contracts\Reports\ReportDefinition;
use App\Enums\PaymentStatus;
use App\Enums\ReportExportKind;
use App\Models\Allocation;
use App\Models\CommissionAccrual;
use App\Models\CostRecord;
use App\Models\Deposit;
use App\Models\OperationalTask;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationChange;
use App\Models\ResourceBlock;
use Carbon\CarbonImmutable;
use DomainException;

final class DatabaseReportDefinition implements ReportDefinition
{
    public function __construct(private readonly ReportExportKind $reportKind) {}

    public function kind(): ReportExportKind
    {
        return $this->reportKind;
    }

    public function capability(): string
    {
        return in_array($this->reportKind, [ReportExportKind::Revenue, ReportExportKind::PaymentsDepositsRefunds, ReportExportKind::CostsMarginCommissions], true)
            ? 'canViewFinance' : 'canManageOperations';
    }

    public function normalizeFilters(array $filters, string $timezone): array
    {
        $unknown = array_diff(array_keys($filters), ['from', 'to', 'status']);
        if ($unknown !== []) {
            throw new DomainException('Unsupported report filter: '.implode(', ', $unknown));
        }
        $from = CarbonImmutable::parse($filters['from'] ?? now($timezone)->startOfMonth()->toDateString(), $timezone)->startOfDay();
        $to = CarbonImmutable::parse($filters['to'] ?? $from->addMonth()->subDay()->toDateString(), $timezone)->addDay()->startOfDay();
        if ($to->lessThanOrEqualTo($from) || $from->diffInDays($to) > 366) {
            throw new DomainException('Report date range must be between one and 366 property-local days.');
        }

        return array_filter([
            'from_local' => $from->toDateString(), 'to_local_exclusive' => $to->toDateString(),
            'from_utc' => $from->utc()->toIso8601String(), 'to_utc_exclusive' => $to->utc()->toIso8601String(),
            'status' => isset($filters['status']) ? (string) $filters['status'] : null,
        ], fn ($value) => $value !== null);
    }

    public function columns(string $locale): array
    {
        return match ($this->reportKind) {
            ReportExportKind::Arrivals => ['property' => 'Property', 'confirmation' => 'Confirmation', 'guest' => 'Primary guest', 'arrival_local' => 'Local arrival', 'nights' => 'Nights', 'occupants' => 'Occupants', 'assignment' => 'Resource/category', 'status' => 'Status', 'deposit_minor' => 'Deposit minor', 'balance_minor' => 'Balance minor', 'arrival_notes' => 'Arrival notes'],
            ReportExportKind::Departures => ['property' => 'Property', 'confirmation' => 'Confirmation', 'guest' => 'Guest', 'departure_local' => 'Local departure', 'assignment' => 'Assigned resource', 'folio_status' => 'Folio status', 'balance_minor' => 'Balance minor', 'housekeeping_state' => 'Housekeeping state'],
            ReportExportKind::Occupancy => ['date' => 'Property-local date', 'sellable_capacity' => 'Sellable capacity', 'blocked_capacity' => 'Blocked capacity', 'occupied_capacity' => 'Occupied capacity', 'arrivals' => 'Arrivals', 'departures' => 'Departures', 'occupancy_percent' => 'Occupancy percent', 'denominator' => 'Denominator definition'],
            ReportExportKind::Revenue => ['reservation_id' => 'Reservation ID', 'confirmation' => 'Reference', 'arrival_local' => 'Arrival', 'departure_local' => 'Departure', 'currency' => 'Native currency', 'booked_minor' => 'Booked minor', 'refunded_minor' => 'Refunded minor', 'net_minor' => 'Net minor'],
            ReportExportKind::PaymentsDepositsRefunds => ['row_type' => 'Row type', 'source_id' => 'Immutable ID', 'reservation_id' => 'Reservation ID', 'origin' => 'Payment origin', 'method' => 'Method', 'channel' => 'Channel', 'entry_mode' => 'Entry mode', 'status' => 'Status', 'reference' => 'Reference', 'deposit_due_minor' => 'Deposit due minor', 'deposit_paid_minor' => 'Deposit paid minor', 'refund_minor' => 'Refund minor', 'amount_minor' => 'Amount minor', 'currency' => 'Native currency', 'occurred_at' => 'Occurred at'],
            ReportExportKind::CostsMarginCommissions => ['source_id' => 'Source ID', 'source_type' => 'Source type', 'occurred_at' => 'Occurrence date', 'category_payee' => 'Category/payee', 'amount_minor' => 'Native amount minor', 'currency' => 'Native currency', 'status' => 'Status'],
            ReportExportKind::Dietary => ['stay_date' => 'Stay date', 'guest_id' => 'Guest ID', 'guest' => 'Guest', 'dietary' => 'Dietary/allergy facts', 'service' => 'Meal/service', 'notes' => 'Operations notes'],
            ReportExportKind::TasksHousekeeping => ['property' => 'Property', 'resource_reservation' => 'Resource/reservation', 'task' => 'Task/category', 'assignee' => 'Assignee', 'due_local' => 'Due local', 'completed_local' => 'Completed local', 'status' => 'Status', 'housekeeping_state' => 'Housekeeping state'],
        };
    }

    public function rows(string $propertyId, array $filters, string $timezone): iterable
    {
        return match ($this->reportKind) {
            ReportExportKind::Arrivals, ReportExportKind::Departures, ReportExportKind::Revenue, ReportExportKind::Dietary => $this->reservationRows($propertyId, $filters, $timezone),
            ReportExportKind::PaymentsDepositsRefunds => $this->paymentRows($propertyId, $filters, $timezone),
            ReportExportKind::CostsMarginCommissions => $this->costRows($propertyId, $filters, $timezone),
            ReportExportKind::TasksHousekeeping => $this->taskRows($propertyId, $filters, $timezone),
            ReportExportKind::Occupancy => $this->occupancyRows($propertyId, $filters, $timezone),
        };
    }

    private function reservationRows(string $propertyId, array $filters, string $timezone): iterable
    {
        $dateField = $this->reportKind === ReportExportKind::Departures ? 'ends_at' : 'starts_at';
        $query = Reservation::query()->with(['property', 'primaryGuest', 'program', 'allocations.resource', 'allocations.requestedCategory', 'payments', 'changes'])
            ->where('property_id', $propertyId)->where($dateField, '>=', $filters['from_utc'])->where($dateField, '<', $filters['to_utc_exclusive'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))->orderBy($dateField)->orderBy('id');
        foreach ($query->cursor() as $reservation) {
            $paid = $reservation->payments->whereIn('status', [PaymentStatus::Succeeded, PaymentStatus::Refunded])->sum('amount_minor');
            $refund = $reservation->changes->where('type', 'refund_completed')->where('status', 'completed')->sum('amount_minor');
            $assignment = $reservation->allocations->first()?->assignmentLabel();
            yield match ($this->reportKind) {
                ReportExportKind::Arrivals => ['property' => $reservation->property->name, 'confirmation' => $reservation->confirmation_number, 'guest' => $this->guestName($reservation), 'arrival_local' => $reservation->starts_at->setTimezone($timezone)->format('Y-m-d H:i'), 'nights' => $reservation->starts_at->diffInDays($reservation->ends_at), 'occupants' => $reservation->adults + $reservation->children, 'assignment' => $assignment, 'status' => $reservation->status->value, 'deposit_minor' => $reservation->deposits()->sum('amount_minor'), 'balance_minor' => $reservation->total_minor - $paid, 'arrival_notes' => $reservation->noteTimeline()->where('kind', 'guest_request')->pluck('body')->implode(' | ')],
                ReportExportKind::Departures => ['property' => $reservation->property->name, 'confirmation' => $reservation->confirmation_number, 'guest' => $this->guestName($reservation), 'departure_local' => $reservation->ends_at->setTimezone($timezone)->format('Y-m-d H:i'), 'assignment' => $assignment, 'folio_status' => $reservation->folio_status->value, 'balance_minor' => $reservation->total_minor - $paid, 'housekeeping_state' => data_get($reservation->allocations->first(), 'resource.housekeeping_status.value')],
                ReportExportKind::Revenue => ['reservation_id' => $reservation->id, 'confirmation' => $reservation->confirmation_number, 'arrival_local' => $reservation->starts_at->setTimezone($timezone)->toDateString(), 'departure_local' => $reservation->ends_at->setTimezone($timezone)->toDateString(), 'currency' => strtoupper($reservation->currency), 'booked_minor' => $reservation->total_minor, 'refunded_minor' => $refund, 'net_minor' => $reservation->total_minor - $refund],
                ReportExportKind::Dietary => ['stay_date' => $reservation->starts_at->setTimezone($timezone)->toDateString(), 'guest_id' => $reservation->primary_guest_id, 'guest' => $this->guestName($reservation), 'dietary' => implode(', ', array_filter((array) data_get($reservation->primaryGuest?->preferences, 'dietary', []))), 'service' => $reservation->program?->name, 'notes' => $reservation->noteTimeline()->where('kind', 'guest_request')->pluck('body')->implode(' | ')],
                default => [],
            };
        }
    }

    private function paymentRows(string $propertyId, array $filters, string $timezone): iterable
    {
        $query = Payment::query()->with('tenderDetail')->whereHas('reservation', fn ($q) => $q->where('property_id', $propertyId))
            ->where('processed_at', '>=', $filters['from_utc'])->where('processed_at', '<', $filters['to_utc_exclusive'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))->orderBy('processed_at')->orderBy('id');
        foreach ($query->cursor() as $payment) {
            yield ['row_type' => 'payment', 'source_id' => $payment->id, 'reservation_id' => $payment->reservation_id, 'origin' => $payment->origin->value, 'method' => $payment->method, 'channel' => $payment->channel->value, 'entry_mode' => $payment->entry_mode->value, 'status' => $payment->status->value, 'reference' => $payment->receipt_safe_reference, 'deposit_due_minor' => null, 'deposit_paid_minor' => null, 'refund_minor' => null, 'amount_minor' => $payment->amount_minor, 'currency' => strtoupper($payment->currency), 'occurred_at' => $payment->processed_at?->setTimezone($timezone)->format('Y-m-d H:i')];
        }
        $deposits = Deposit::query()->whereHas('reservation', fn ($query) => $query->where('property_id', $propertyId))
            ->where(function ($query) use ($filters): void {
                $query->where(fn ($due) => $due->where('due_at', '>=', $filters['from_utc'])->where('due_at', '<', $filters['to_utc_exclusive']))
                    ->orWhere(fn ($paid) => $paid->where('paid_at', '>=', $filters['from_utc'])->where('paid_at', '<', $filters['to_utc_exclusive']));
            })->orderBy('due_at')->orderBy('id');
        foreach ($deposits->cursor() as $deposit) {
            $paidMinor = in_array($deposit->status->value, ['paid', 'refunded'], true) ? $deposit->amount_minor : 0;
            yield ['row_type' => 'deposit', 'source_id' => $deposit->id, 'reservation_id' => $deposit->reservation_id, 'origin' => null, 'method' => null, 'channel' => null, 'entry_mode' => null, 'status' => $deposit->status->value, 'reference' => $deposit->payment_id, 'deposit_due_minor' => $deposit->amount_minor, 'deposit_paid_minor' => $paidMinor, 'refund_minor' => null, 'amount_minor' => $deposit->amount_minor, 'currency' => strtoupper($deposit->currency), 'occurred_at' => ($deposit->paid_at ?? $deposit->due_at)?->setTimezone($timezone)->format('Y-m-d H:i')];
        }
        $refunds = ReservationChange::query()->whereHas('reservation', fn ($query) => $query->where('property_id', $propertyId))
            ->whereIn('type', ['refund_requested', 'refund_completed'])->where('occurred_at', '>=', $filters['from_utc'])->where('occurred_at', '<', $filters['to_utc_exclusive'])
            ->orderBy('occurred_at')->orderBy('id');
        foreach ($refunds->cursor() as $refund) {
            yield ['row_type' => 'refund', 'source_id' => $refund->id, 'reservation_id' => $refund->reservation_id, 'origin' => null, 'method' => null, 'channel' => null, 'entry_mode' => null, 'status' => $refund->status, 'reference' => $refund->reference, 'deposit_due_minor' => null, 'deposit_paid_minor' => null, 'refund_minor' => $refund->amount_minor, 'amount_minor' => $refund->amount_minor, 'currency' => strtoupper($refund->currency ?? ''), 'occurred_at' => $refund->occurred_at->setTimezone($timezone)->format('Y-m-d H:i')];
        }
    }

    private function costRows(string $propertyId, array $filters, string $timezone): iterable
    {
        foreach (CostRecord::query()->where(fn ($q) => $q->whereHas('reservation', fn ($r) => $r->where('property_id', $propertyId))->orWhereHas('program', fn ($p) => $p->where('property_id', $propertyId)))->where('occurred_at', '>=', $filters['from_utc'])->where('occurred_at', '<', $filters['to_utc_exclusive'])->orderBy('occurred_at')->orderBy('id')->cursor() as $cost) {
            yield ['source_id' => $cost->id, 'source_type' => 'cost', 'occurred_at' => $cost->occurred_at->setTimezone($timezone)->format('Y-m-d H:i'), 'category_payee' => $cost->category, 'amount_minor' => $cost->amount_minor, 'currency' => strtoupper($cost->currency), 'status' => 'recorded'];
        }
        foreach (CommissionAccrual::query()->whereHas('reservation', fn ($q) => $q->where('property_id', $propertyId))->where('created_at', '>=', $filters['from_utc'])->where('created_at', '<', $filters['to_utc_exclusive'])->orderBy('created_at')->orderBy('id')->cursor() as $commission) {
            yield ['source_id' => $commission->id, 'source_type' => 'commission', 'occurred_at' => $commission->created_at?->setTimezone($timezone)->format('Y-m-d H:i'), 'category_payee' => $commission->payee_name, 'amount_minor' => $commission->amount_minor, 'currency' => strtoupper($commission->currency), 'status' => $commission->status];
        }
    }

    private function taskRows(string $propertyId, array $filters, string $timezone): iterable
    {
        $query = OperationalTask::query()->with(['property', 'reservation', 'assignee'])->where('property_id', $propertyId)->where('due_at', '>=', $filters['from_utc'])->where('due_at', '<', $filters['to_utc_exclusive'])->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))->orderBy('due_at')->orderBy('id');
        foreach ($query->cursor() as $task) {
            yield ['property' => $task->property->name, 'resource_reservation' => $task->reservation_id !== null ? $task->reservation->confirmation_number : data_get($task->metadata, 'resource_name'), 'task' => $task->title, 'assignee' => $task->assignee?->name, 'due_local' => $task->due_at?->setTimezone($timezone)->format('Y-m-d H:i'), 'completed_local' => $task->completed_at?->setTimezone($timezone)->format('Y-m-d H:i'), 'status' => $task->status->value, 'housekeeping_state' => data_get($task->metadata, 'housekeeping_state')];
        }
    }

    private function occupancyRows(string $propertyId, array $filters, string $timezone): iterable
    {
        $day = CarbonImmutable::parse($filters['from_local'], $timezone);
        $end = CarbonImmutable::parse($filters['to_local_exclusive'], $timezone);
        while ($day->lessThan($end)) {
            $startUtc = $day->utc();
            $endUtc = $day->addDay()->utc();
            $occupied = Allocation::query()->whereHas('reservation', fn ($query) => $query->where('property_id', $propertyId)->whereIn('status', ['confirmed', 'checked_in', 'checked_out']))
                ->whereHas('requestedCategory', fn ($query) => $query->where('counts_as_stay', true))->where('starts_at', '<', $endUtc)->where('ends_at', '>', $startUtc)->sum('quantity');
            $capacity = \App\Models\Resource::query()->where('property_id', $propertyId)->where('is_active', true)->whereHas('category', fn ($query) => $query->where('counts_as_stay', true))->count();
            $blocked = ResourceBlock::query()->whereHas('resource', fn ($query) => $query->where('property_id', $propertyId)->whereHas('category', fn ($category) => $category->where('counts_as_stay', true)))->where('starts_at', '<', $endUtc)->where('ends_at', '>', $startUtc)->distinct('resource_id')->count('resource_id');
            $sellable = max(0, $capacity - $blocked);
            $arrivals = Reservation::query()->where('property_id', $propertyId)->where('starts_at', '>=', $startUtc)->where('starts_at', '<', $endUtc)->count();
            $departures = Reservation::query()->where('property_id', $propertyId)->where('ends_at', '>=', $startUtc)->where('ends_at', '<', $endUtc)->count();
            yield ['date' => $day->toDateString(), 'sellable_capacity' => $sellable, 'blocked_capacity' => $blocked, 'occupied_capacity' => $occupied, 'arrivals' => $arrivals, 'departures' => $departures, 'occupancy_percent' => $sellable > 0 ? round($occupied / $sellable * 100, 2) : 0, 'denominator' => 'active resources minus resources with an overlapping block'];
            $day = $day->addDay();
        }
    }

    private function guestName(Reservation $reservation): string
    {
        return trim($reservation->primaryGuest->first_name.' '.$reservation->primaryGuest->last_name);
    }
}
