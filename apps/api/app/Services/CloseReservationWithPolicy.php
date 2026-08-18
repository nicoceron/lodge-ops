<?php

namespace App\Services;

use App\Enums\AllocationStatus;
use App\Enums\FolioLineType;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\CommercialWorkflowException as DomainException;
use App\Models\Reservation;
use App\Models\ReservationStatusHistory;
use App\Services\Automation\OutboxRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CloseReservationWithPolicy
{
    public function __construct(
        private readonly CancellationFeeCalculator $fees,
        private readonly FolioService $folio,
        private readonly ReservationPaymentScheduleService $paymentSchedule,
        private readonly ReservationChangeRecorder $changes,
        private readonly OutboxRecorder $outbox,
    ) {}

    public function handle(Reservation $reservation, ReservationStatus $target, string $reason, ?int $actorId): Reservation
    {
        return DB::transaction(function () use ($reservation, $target, $reason, $actorId): Reservation {
            if (! in_array($target, [ReservationStatus::Cancelled, ReservationStatus::NoShow], true)) {
                throw new \LogicException('Policy closure only supports cancellation and no-show.');
            }
            $locked = Reservation::query()->with('allocations')->lockForUpdate()->findOrFail($reservation->id);
            if ($locked->status === $target) {
                return $locked->load('changes.actor');
            }
            if (! $locked->status->canTransitionTo($target)) {
                throw ValidationException::withMessages(['status' => "The reservation cannot transition from {$locked->status->value} to {$target->value}."]);
            }
            $reason = trim($reason);
            if ($reason === '') {
                throw new DomainException('A reason is required when cancelling a reservation or recording a no-show.');
            }

            $before = $this->changes->snapshot($locked);
            $calculation = $this->fees->calculate($locked);
            $releaseAmount = $locked->total_minor - $calculation['fee_minor'];
            if ($releaseAmount > 0) {
                $this->folio->append(
                    $locked,
                    FolioLineType::Adjustment,
                    ($target === ReservationStatus::NoShow ? 'No-show' : 'Cancellation').' policy adjustment',
                    1000,
                    $calculation['fee_minor'] - $locked->subtotal_minor,
                    $actorId,
                    [
                        'policy_tier' => $calculation['tier'],
                        'days_before_arrival' => $calculation['days_before_arrival'],
                        'property_timezone' => $calculation['property_timezone'],
                        'effective_at_utc' => $calculation['effective_at_utc'],
                        'effective_local_date' => $calculation['effective_local_date'],
                        'arrival_local_date' => $calculation['arrival_local_date'],
                    ],
                    -$locked->tax_minor,
                );
            }

            $previous = $locked->status;
            $locked->update([
                'status' => $target,
                'cancelled_at' => now(),
                'closure_reason' => $reason,
                'hold_expires_at' => null,
                'revision' => $locked->revision + 1,
            ]);
            $locked->allocations()->where('status', '!=', AllocationStatus::Released)->update(['status' => AllocationStatus::Released]);
            $waivedDeposits = $this->paymentSchedule->waiveOpen($locked, ucfirst(str_replace('_', ' ', $target->value)).': '.$reason, $actorId);
            $paid = (int) $locked->payments()->where('status', PaymentStatus::Succeeded)->sum('amount_minor');
            $completedRefunds = (int) $locked->changes()
                ->where('type', 'refund_completed')->where('status', 'completed')->sum('amount_minor');
            $refundRequirement = max(0, $paid - $completedRefunds - $calculation['fee_minor']);

            ReservationStatusHistory::query()->create([
                'reservation_id' => $locked->id,
                'actor_id' => $actorId,
                'from_status' => $previous,
                'to_status' => $target,
                'changed_at' => now(),
                'metadata' => [
                    'reason' => $reason,
                    'fee_minor' => $calculation['fee_minor'],
                    'refund_requirement_minor' => $refundRequirement,
                ],
            ]);
            $locked->unsetRelation('allocations');
            $change = $this->changes->record($locked, $target === ReservationStatus::NoShow ? 'no_show' : 'cancellation', [
                'actor_id' => $actorId,
                'amount_minor' => $calculation['fee_minor'],
                'before_snapshot' => $before,
                'after_snapshot' => $this->changes->snapshot($locked->fresh('allocations')),
                'metadata' => [
                    'reason' => $reason,
                    'policy_tier' => $calculation['tier'],
                    'days_before_arrival' => $calculation['days_before_arrival'],
                    'property_timezone' => $calculation['property_timezone'],
                    'effective_at_utc' => $calculation['effective_at_utc'],
                    'effective_local_date' => $calculation['effective_local_date'],
                    'arrival_local_date' => $calculation['arrival_local_date'],
                    'released_value_minor' => $releaseAmount,
                    'waived_deposits' => $waivedDeposits,
                    'paid_minor' => $paid,
                    'refund_requirement_minor' => $refundRequirement,
                ],
            ]);
            $this->outbox->record('reservation', $locked->id, 'reservation.status_changed', [
                'reservation_id' => $locked->id,
                'change_id' => $change->id,
                'status' => $target->value,
                'reason' => $reason,
                'fee_minor' => $calculation['fee_minor'],
                'refund_requirement_minor' => $refundRequirement,
            ]);

            return $locked->fresh(['allocations.requestedCategory', 'allocations.resource', 'changes.actor']);
        }, 3);
    }
}
