<?php

namespace App\Services;

use App\Enums\AllocationStatus;
use App\Enums\DepositStatus;
use App\Enums\TaskStatus;
use App\Models\Allocation;
use App\Models\Membership;
use App\Models\Reservation;
use App\Models\ServiceOccurrence;
use App\Services\Automation\OutboxRecorder;
use RuntimeException;

class ReservationConfirmationProvisioner
{
    public function __construct(private OutboxRecorder $outbox, private AvailabilityService $availability) {}

    public function provision(Reservation $reservation): void
    {
        if (app()->environment('testing') && config('direct-booking.testing.fail_confirmation_provisioning') === true) {
            throw new RuntimeException('Injected confirmation provisioning failure.');
        }
        $this->serviceOccurrence($reservation);
        $this->tasks($reservation);
        $this->paymentSchedule($reservation);
    }

    private function serviceOccurrence(Reservation $reservation): void
    {
        if ($reservation->program_id === null || $reservation->allocations()->whereNotNull('service_occurrence_id')->exists()) {
            return;
        }
        $reservation->loadMissing('program');
        $program = $reservation->program;
        if ($program === null) {
            return;
        }
        $partySize = max(1, $reservation->adults + $reservation->children);
        $startsAt = $reservation->starts_at;
        $endsAt = $startsAt->addMinutes(max(1, $program->default_duration_minutes ?? 60));
        $occurrence = ServiceOccurrence::query()->create([
            'program_id' => $program->id,
            'property_id' => $reservation->property_id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'capacity' => max($partySize, $program->capacity ?? $partySize),
            'is_cancelled' => false,
        ]);
        $allocation = Allocation::query()->create([
            'reservation_id' => $reservation->id,
            'service_occurrence_id' => $occurrence->id,
            'status' => AllocationStatus::Tentative,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'quantity' => $partySize,
        ]);
        $this->availability->assertAvailable($allocation);
        $allocation->forceFill(['status' => AllocationStatus::Confirmed])->save();
    }

    private function tasks(Reservation $reservation): void
    {
        if ($reservation->program_id === null) {
            return;
        }

        $reservation->loadMissing('program.taskTemplates');
        foreach ($reservation->program?->taskTemplates->where('is_active', true) ?? [] as $template) {
            $assigneeId = $template->assignee_role
                ? Membership::query()
                    ->where('role', $template->assignee_role)
                    ->where('is_active', true)
                    ->where(function ($query) use ($reservation): void {
                        $query->whereNull('property_id')->orWhere('property_id', $reservation->property_id);
                    })
                    ->orderByRaw('CASE WHEN property_id IS NULL THEN 1 ELSE 0 END')
                    ->value('user_id')
                : null;

            $reservation->operationalTasks()->firstOrCreate(
                ['program_task_template_id' => $template->id],
                [
                    'property_id' => $reservation->property_id,
                    'assignee_id' => $assigneeId,
                    'title' => $template->title,
                    'description' => $template->description,
                    'status' => TaskStatus::Todo,
                    'priority' => $template->priority,
                    'due_at' => $reservation->starts_at->addMinutes($template->due_offset_minutes),
                    'metadata' => ['generated_from' => 'program_task_template'],
                ],
            );
        }
    }

    private function paymentSchedule(Reservation $reservation): void
    {
        $policy = $reservation->deposit_policy_snapshot ?? [];
        $depositScheduleType = $policy === [] ? 'deposit_50' : 'deposit';
        $depositAmount = match ($policy['requirement_type'] ?? 'percentage') {
            'fixed' => min($reservation->total_minor, (int) ($policy['fixed_amount_minor'] ?? 0)),
            default => intdiv(($reservation->total_minor * (int) ($policy['percentage_basis_points'] ?? 5000)) + 9999, 10000),
        };
        $balanceAmount = $reservation->total_minor - $depositAmount;
        $balanceDue = $reservation->starts_at->subDays((int) ($policy['balance_due_offset_days'] ?? 30));
        if ($balanceDue->isPast()) {
            $balanceDue = now();
        }

        foreach ([
            $depositScheduleType => [$depositAmount, now()->addDays((int) ($policy['deposit_due_offset_days'] ?? 0))],
            'balance' => [$balanceAmount, $balanceDue],
        ] as $type => [$amount, $dueAt]) {
            $deposit = $reservation->deposits()->firstOrCreate(
                ['schedule_type' => $type],
                [
                    'status' => DepositStatus::Due,
                    'currency' => $reservation->currency,
                    'amount_minor' => $amount,
                    'due_at' => $dueAt,
                ],
            );
            if ($deposit->wasRecentlyCreated) {
                $this->outbox->record('deposit', $deposit->id, 'deposit.created', [
                    'deposit_id' => $deposit->id,
                    'reservation_id' => $reservation->id,
                    'schedule_type' => $type,
                    'amount_minor' => $deposit->amount_minor,
                    'due_at' => $deposit->due_at?->toIso8601String(),
                ]);
            }
        }
    }
}
