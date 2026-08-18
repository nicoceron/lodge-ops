<?php

namespace App\Services;

use App\Enums\DepositStatus;
use App\Enums\TaskStatus;
use App\Models\Membership;
use App\Models\Reservation;
use App\Services\Automation\OutboxRecorder;

class ReservationConfirmationProvisioner
{
    public function __construct(private OutboxRecorder $outbox) {}

    public function provision(Reservation $reservation): void
    {
        $this->tasks($reservation);
        $this->paymentSchedule($reservation);
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
