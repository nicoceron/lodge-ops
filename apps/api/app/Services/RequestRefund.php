<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationChange;
use App\Services\Automation\OutboxRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RequestRefund
{
    public function __construct(
        private readonly FolioService $folio,
        private readonly ReservationChangeRecorder $changes,
        private readonly OutboxRecorder $outbox,
    ) {}

    public function handle(Reservation $reservation, Payment $payment, int $amountMinor, string $reason, ?int $actorId): ReservationChange
    {
        return DB::transaction(function () use ($reservation, $payment, $amountMinor, $reason, $actorId): ReservationChange {
            $lockedReservation = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($lockedPayment->reservation_id !== $lockedReservation->id || $lockedPayment->status !== PaymentStatus::Succeeded) {
                throw ValidationException::withMessages(['payment_id' => 'Refunds require a reconciled payment on this reservation.']);
            }
            if ($amountMinor <= 0) {
                throw ValidationException::withMessages(['amount_minor' => 'The refund amount must be greater than zero.']);
            }
            $reason = trim($reason);
            if ($reason === '') {
                throw ValidationException::withMessages(['reason' => 'A refund reason is required.']);
            }

            $paymentRefunds = (int) $lockedReservation->changes()
                ->whereIn('type', ['refund_requested', 'refund_completed'])
                ->whereIn('status', ['requested', 'completed'])
                ->where('metadata->payment_id', $lockedPayment->id)
                ->get()
                ->reject(fn (ReservationChange $change): bool => $change->type === 'refund_requested'
                    && $change->events()->where('type', 'refund_completed')->exists())
                ->sum('amount_minor');
            $openRequests = (int) $lockedReservation->changes()
                ->where('type', 'refund_requested')->where('status', 'requested')->get()
                ->reject(fn (ReservationChange $change): bool => $change->events()->where('type', 'refund_completed')->exists())
                ->sum('amount_minor');
            $availableOnPayment = max(0, $lockedPayment->amount_minor - $paymentRefunds);
            $availableCredit = max(0, -$this->folio->summary($lockedReservation)['balance_minor'] - $openRequests);
            if ($amountMinor > min($availableOnPayment, $availableCredit)) {
                throw ValidationException::withMessages(['amount_minor' => 'The requested refund exceeds the payment or guest credit available.']);
            }

            $request = $this->changes->record($lockedReservation, 'refund_requested', [
                'actor_id' => $actorId,
                'status' => 'requested',
                'amount_minor' => $amountMinor,
                'metadata' => [
                    'payment_id' => $lockedPayment->id,
                    'reason' => $reason,
                    'scope' => $amountMinor === $availableOnPayment ? 'full' : 'partial',
                    'available_credit_before_minor' => $availableCredit,
                ],
            ]);
            $this->outbox->record('reservation', $lockedReservation->id, 'refund.requested', [
                'reservation_id' => $lockedReservation->id,
                'refund_request_id' => $request->id,
                'payment_id' => $lockedPayment->id,
                'amount_minor' => $amountMinor,
            ]);

            return $request;
        }, 3);
    }
}
