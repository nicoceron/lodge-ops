<?php

namespace App\Services;

use App\Enums\DepositStatus;
use App\Exceptions\CommercialWorkflowException as DomainException;
use App\Models\Deposit;
use App\Models\Reservation;
use App\Services\Automation\OutboxRecorder;
use Illuminate\Support\Facades\DB;

final class DepositService
{
    public function __construct(private readonly OutboxRecorder $outbox) {}

    public function create(Reservation $reservation, int $amountMinor, mixed $dueAt): Deposit
    {
        return DB::transaction(function () use ($reservation, $amountMinor, $dueAt): Deposit {
            $deposit = Deposit::query()->create([
                'reservation_id' => $reservation->id,
                'status' => DepositStatus::Due,
                'currency' => $reservation->currency,
                'amount_minor' => $amountMinor,
                'due_at' => $dueAt,
            ]);

            $this->outbox->record('deposit', $deposit->id, 'deposit.created', [
                'deposit_id' => $deposit->id,
                'reservation_id' => $reservation->id,
                'amount_minor' => $deposit->amount_minor,
                'due_at' => $deposit->due_at?->toIso8601String(),
            ]);

            return $deposit;
        });
    }

    public function waive(Deposit $deposit, string $reason, ?int $actorId): Deposit
    {
        return DB::transaction(function () use ($deposit, $reason, $actorId): Deposit {
            $locked = Deposit::query()->lockForUpdate()->findOrFail($deposit->id);
            if ($locked->status === DepositStatus::Waived) {
                return $locked;
            }
            if ($locked->status !== DepositStatus::Due) {
                throw new DomainException('Only a due deposit may be waived.');
            }

            $locked->update([
                'status' => DepositStatus::Waived,
                'waived_at' => now(),
                'waived_by' => $actorId,
                'waiver_reason' => trim($reason),
            ]);
            $this->outbox->record('deposit', $locked->id, 'deposit.waived', [
                'deposit_id' => $locked->id,
                'reservation_id' => $locked->reservation_id,
                'reason' => trim($reason),
            ]);

            return $locked->fresh();
        });
    }
}
