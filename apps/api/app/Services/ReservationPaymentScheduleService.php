<?php

namespace App\Services;

use App\Enums\DepositStatus;
use App\Enums\PaymentStatus;
use App\Models\Deposit;
use App\Models\Reservation;
use App\Services\Automation\OutboxRecorder;

final class ReservationPaymentScheduleService
{
    public function __construct(private readonly OutboxRecorder $outbox) {}

    /** @return array{paid_minor:int,due_minor:int,refund_requirement_minor:int} */
    public function rebuild(Reservation $reservation, string $reason, ?int $actorId): array
    {
        $paid = (int) $reservation->payments()->where('status', PaymentStatus::Succeeded)->sum('amount_minor');
        $remaining = max(0, $reservation->total_minor - $paid);
        $refundRequirement = max(0, $paid - $reservation->total_minor);

        $reservation->deposits()->where('status', DepositStatus::Due)->lockForUpdate()->get()
            ->each(function (Deposit $deposit) use ($reason, $actorId): void {
                $deposit->update([
                    'status' => DepositStatus::Waived,
                    'waived_at' => now(),
                    'waived_by' => $actorId,
                    'waiver_reason' => $reason,
                ]);
            });

        if ($remaining > 0) {
            $policy = $reservation->deposit_policy_snapshot ?? [];
            $requiredDeposit = match ($policy['requirement_type'] ?? 'percentage') {
                'fixed' => min($reservation->total_minor, (int) ($policy['fixed_amount_minor'] ?? 0)),
                default => intdiv(($reservation->total_minor * (int) ($policy['percentage_basis_points'] ?? 5000)) + 9999, 10000),
            };
            $depositOutstanding = min($remaining, max(0, $requiredDeposit - $paid));
            $balanceOutstanding = $remaining - $depositOutstanding;

            foreach ([
                'deposit' => [$depositOutstanding, now()->addDays((int) ($policy['deposit_due_offset_days'] ?? 0))],
                'balance' => [$balanceOutstanding, max(now(), $reservation->starts_at->subDays((int) ($policy['balance_due_offset_days'] ?? 30)))],
            ] as $kind => [$amount, $dueAt]) {
                if ($amount <= 0) {
                    continue;
                }
                $deposit = Deposit::query()->create([
                    'reservation_id' => $reservation->id,
                    'schedule_type' => "revision_{$reservation->revision}_{$kind}",
                    'status' => DepositStatus::Due,
                    'currency' => $reservation->currency,
                    'amount_minor' => $amount,
                    'due_at' => $dueAt,
                ]);
                $this->outbox->record('deposit', $deposit->id, 'deposit.created', [
                    'deposit_id' => $deposit->id,
                    'reservation_id' => $reservation->id,
                    'schedule_type' => $deposit->schedule_type,
                    'amount_minor' => $deposit->amount_minor,
                    'due_at' => $deposit->due_at?->toIso8601String(),
                    'reason' => $reason,
                ]);
            }
        }

        return [
            'paid_minor' => $paid,
            'due_minor' => $remaining,
            'refund_requirement_minor' => $refundRequirement,
        ];
    }

    public function waiveOpen(Reservation $reservation, string $reason, ?int $actorId): int
    {
        $count = 0;
        $reservation->deposits()->where('status', DepositStatus::Due)->lockForUpdate()->get()
            ->each(function (Deposit $deposit) use ($reason, $actorId, &$count): void {
                $deposit->update([
                    'status' => DepositStatus::Waived,
                    'waived_at' => now(),
                    'waived_by' => $actorId,
                    'waiver_reason' => $reason,
                ]);
                $count++;
            });

        return $count;
    }
}
