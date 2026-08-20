<?php

namespace App\Services;

use App\Enums\DepositStatus;
use App\Enums\DocumentKind;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\ProviderDispute;
use App\Models\Reservation;
use App\Models\ReservationChange;
use App\Services\Automation\OutboxRecorder;
use App\Services\Documents\RequestDocumentGeneration;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CompleteRefund
{
    public function __construct(
        private readonly FolioService $folio,
        private readonly ReservationChangeRecorder $changes,
        private readonly OutboxRecorder $outbox,
        private readonly RequestDocumentGeneration $documents,
    ) {}

    public function handle(ReservationChange $request, string $reference, ?int $actorId): ReservationChange
    {
        return DB::transaction(function () use ($request, $reference, $actorId): ReservationChange {
            [$reservation, $payment, $lockedRequest] = $this->lockRequestGraph($request);
            $this->assertRequest($lockedRequest);
            $existing = $lockedRequest->events()->where('type', 'refund_completed')->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }
            $reference = trim($reference);
            if ($reference === '') {
                throw ValidationException::withMessages(['reference' => 'An internal or provider refund reference is required.']);
            }
            if ($payment->reservation_id !== $reservation->id || $payment->status !== PaymentStatus::Succeeded) {
                throw ValidationException::withMessages(['payment_id' => 'The source payment is no longer refundable.']);
            }
            $completedForPayment = (int) $reservation->changes()
                ->where('type', 'refund_completed')->where('status', 'completed')
                ->where('metadata->payment_id', $payment->id)->sum('amount_minor');
            $lostChargebacks = 0;
            $chargebacks = ProviderDispute::query()
                ->where('payment_id', $payment->id)
                ->where('state', 'lost')
                ->where('impact_state', 'applied')
                ->lockForUpdate()
                ->get(['amount_minor']);
            foreach ($chargebacks as $chargeback) {
                $lostChargebacks += $chargeback->amount_minor;
            }
            if ($completedForPayment + $lockedRequest->amount_minor > max(0, $payment->amount_minor - $lostChargebacks)) {
                throw ValidationException::withMessages(['amount_minor' => 'Completing this refund would exceed the source payment.']);
            }

            $completed = $this->changes->record($reservation, 'refund_completed', [
                'actor_id' => $actorId,
                'parent_change_id' => $lockedRequest->id,
                'status' => 'completed',
                'amount_minor' => $lockedRequest->amount_minor,
                'reference' => $reference,
                'deduplication_key' => 'refund-completed:'.$lockedRequest->id,
                'metadata' => [
                    'payment_id' => $payment->id,
                    'reason' => data_get($lockedRequest->metadata, 'reason'),
                    'scope' => data_get($lockedRequest->metadata, 'scope'),
                ],
            ]);
            $this->folio->postRefund($payment->load('reservation'), $completed, $actorId);

            if ($completedForPayment + $completed->amount_minor === $payment->amount_minor) {
                $payment->update(['status' => PaymentStatus::Refunded]);
                $payment->deposits()->where('status', DepositStatus::Paid)->update(['status' => DepositStatus::Refunded]);
            }
            $this->outbox->record('reservation', $reservation->id, 'refund.completed', [
                'reservation_id' => $reservation->id,
                'refund_request_id' => $lockedRequest->id,
                'refund_change_id' => $completed->id,
                'payment_id' => $payment->id,
                'amount_minor' => $completed->amount_minor,
                'reference' => $reference,
            ]);
            $this->documents->handleSystem(
                $reservation,
                DocumentKind::RefundReceipt,
                app()->getLocale(),
                'refund-receipt:'.$completed->id,
                $payment,
                $completed,
            );

            return $completed;
        }, 3);
    }

    public function fail(ReservationChange $request, string $reason, ?int $actorId): ReservationChange
    {
        return DB::transaction(function () use ($request, $reason, $actorId): ReservationChange {
            [, , $lockedRequest] = $this->lockRequestGraph($request);
            $this->assertRequest($lockedRequest);
            if ($lockedRequest->events()->where('type', 'refund_completed')->exists()) {
                throw ValidationException::withMessages(['refund' => 'A completed refund cannot be marked failed.']);
            }
            $reason = trim($reason);
            if ($reason === '') {
                throw ValidationException::withMessages(['reason' => 'A failure reason is required.']);
            }
            $attempt = $lockedRequest->events()->where('type', 'refund_failed')->count() + 1;

            return $this->changes->record($lockedRequest->reservation, 'refund_failed', [
                'actor_id' => $actorId,
                'parent_change_id' => $lockedRequest->id,
                'status' => 'failed',
                'amount_minor' => $lockedRequest->amount_minor,
                'deduplication_key' => "refund-failed:{$lockedRequest->id}:{$attempt}",
                'metadata' => [
                    'payment_id' => data_get($lockedRequest->metadata, 'payment_id'),
                    'reason' => $reason,
                    'attempt' => $attempt,
                    'retryable' => true,
                ],
            ]);
        }, 3);
    }

    private function assertRequest(ReservationChange $request): void
    {
        if ($request->type !== 'refund_requested' || $request->status !== 'requested') {
            throw ValidationException::withMessages(['refund' => 'The selected change is not a refund request.']);
        }
    }

    /** @return array{Reservation, Payment, ReservationChange} */
    private function lockRequestGraph(ReservationChange $request): array
    {
        $snapshot = ReservationChange::query()->findOrFail($request->id);
        $reservation = Reservation::query()->lockForUpdate()->findOrFail($snapshot->reservation_id);
        $payment = Payment::query()->lockForUpdate()->findOrFail(data_get($snapshot->metadata, 'payment_id'));
        $lockedRequest = ReservationChange::query()->lockForUpdate()->findOrFail($snapshot->id);

        return [$reservation, $payment, $lockedRequest];
    }
}
