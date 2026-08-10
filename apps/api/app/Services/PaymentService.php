<?php

namespace App\Services;

use App\Enums\DepositStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\CommercialWorkflowException as DomainException;
use App\Models\Deposit;
use App\Models\Payment;
use App\Models\Reservation;
use App\Services\Automation\OutboxRecorder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class PaymentService
{
    public function __construct(
        private readonly FolioService $folio,
        private readonly OutboxRecorder $outbox,
    ) {}

    /** @param array<string, mixed> $data */
    public function recordManual(array $data, ?int $actorId, bool $capture = false): Payment
    {
        return DB::transaction(function () use ($data, $actorId, $capture): Payment {
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($data['reservation_id']);

            if (! empty($data['provider']) && ! empty($data['provider_reference'])) {
                $existing = Payment::query()
                    ->where('provider', $data['provider'])
                    ->where('provider_reference', $data['provider_reference'])
                    ->first();
                if ($existing !== null) {
                    return $existing;
                }
            }

            $payment = Payment::query()->create([
                ...Arr::only($data, [
                    'reservation_id',
                    'method',
                    'provider',
                    'provider_reference',
                    'evidence_url',
                    'evidence_note',
                    'amount_minor',
                    'metadata',
                ]),
                'currency' => $reservation->currency,
                'status' => PaymentStatus::Pending,
                'recorded_by' => $actorId,
            ]);

            $this->outbox->record('payment', $payment->id, 'payment.created', [
                'payment_id' => $payment->id,
                'reservation_id' => $reservation->id,
                'amount_minor' => $payment->amount_minor,
            ]);

            if (! $capture) {
                return $payment;
            }

            $reconciled = $this->reconcile($payment, $actorId, $data['deposit_id'] ?? null);
            $reconciled->wasRecentlyCreated = true;

            return $reconciled;
        }, 3);
    }

    public function reconcile(Payment $payment, ?int $actorId, ?string $depositId = null): Payment
    {
        return DB::transaction(function () use ($payment, $actorId, $depositId): Payment {
            $locked = Payment::query()->with('reservation')->lockForUpdate()->findOrFail($payment->id);
            if ($locked->status === PaymentStatus::Succeeded) {
                return $locked;
            }
            if ($locked->status !== PaymentStatus::Pending) {
                throw new DomainException('Only pending payments may be reconciled.');
            }

            $deposit = $depositId === null
                ? null
                : Deposit::query()->lockForUpdate()->findOrFail($depositId);
            if ($deposit !== null) {
                if ($deposit->reservation_id !== $locked->reservation_id) {
                    throw new DomainException('The deposit and payment must belong to the same reservation.');
                }
                if ($deposit->status !== DepositStatus::Due) {
                    throw new DomainException('Only a due deposit may be reconciled.');
                }
                if ($locked->amount_minor < $deposit->amount_minor) {
                    throw new DomainException('The payment does not cover the selected deposit.');
                }
            }

            $locked->update([
                'status' => PaymentStatus::Succeeded,
                'processed_at' => now(),
                'reconciled_at' => now(),
                'reconciled_by' => $actorId,
            ]);
            $this->folio->postPayment($locked->fresh(['reservation']), $actorId);

            if ($deposit !== null) {
                $deposit->update([
                    'payment_id' => $locked->id,
                    'status' => DepositStatus::Paid,
                    'paid_at' => now(),
                ]);
            }

            $this->outbox->record('payment', $locked->id, 'payment.succeeded', [
                'payment_id' => $locked->id,
                'reservation_id' => $locked->reservation_id,
                'deposit_id' => $deposit?->id,
                'amount_minor' => $locked->amount_minor,
            ]);

            return $locked->fresh(['reservation', 'deposits']);
        }, 3);
    }

    public function reverse(Payment $payment, string $reason, ?int $actorId): Payment
    {
        return DB::transaction(function () use ($payment, $reason, $actorId): Payment {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($locked->status === PaymentStatus::Reversed) {
                return $locked;
            }
            if ($locked->status !== PaymentStatus::Succeeded) {
                throw new DomainException('Only reconciled payments may be reversed.');
            }

            $paymentLine = $locked->folioLines()
                ->where('type', 'payment')
                ->lockForUpdate()
                ->firstOrFail();
            $this->folio->reverse($paymentLine, $reason, $actorId);

            $locked->update([
                'status' => PaymentStatus::Reversed,
                'reversed_at' => now(),
                'reversed_by' => $actorId,
                'reversal_reason' => trim($reason),
            ]);
            $locked->deposits()->where('status', DepositStatus::Paid)->update([
                'status' => DepositStatus::Refunded,
            ]);

            $this->outbox->record('payment', $locked->id, 'payment.reversed', [
                'payment_id' => $locked->id,
                'reservation_id' => $locked->reservation_id,
                'reason' => trim($reason),
            ]);

            return $locked->fresh(['reservation', 'deposits']);
        }, 3);
    }
}
