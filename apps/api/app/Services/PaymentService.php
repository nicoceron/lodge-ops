<?php

namespace App\Services;

use App\Data\Payments\ProviderPayment;
use App\Enums\DepositStatus;
use App\Enums\PaymentOrigin;
use App\Enums\PaymentStatus;
use App\Exceptions\CommercialWorkflowException as DomainException;
use App\Models\Deposit;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentRequest;
use App\Models\Reservation;
use App\Models\ReservationChange;
use App\Services\Automation\OutboxRecorder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class PaymentService
{
    public function __construct(
        private readonly FolioService $folio,
        private readonly OutboxRecorder $outbox,
    ) {}

    public function recordProvider(PaymentAttempt $attempt, ProviderPayment $providerPayment): Payment
    {
        return DB::transaction(function () use ($attempt, $providerPayment): Payment {
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($attempt->reservation_id);
            $request = PaymentRequest::query()->lockForUpdate()->findOrFail($attempt->payment_request_id);
            $lockedAttempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            $existing = Payment::query()->where('provider', $lockedAttempt->provider)
                ->where('provider_reference', $providerPayment->id)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($request->payment_id === null) {
                    $request->update(['payment_id' => $existing->id, 'state' => 'paid', 'paid_at' => $existing->processed_at ?? now()]);
                }

                return $existing;
            }
            if ($request->payment_id !== null) {
                throw new DomainException('The payment request is already satisfied by another payment.');
            }
            $outstanding = max(0, $this->folio->summary($reservation)['balance_minor']);
            if ($request->source_amount_minor > $outstanding) {
                throw new DomainException('The provider payment no longer matches the reservation outstanding balance.');
            }
            $deposit = $lockedAttempt->deposit_id === null ? null : Deposit::query()->lockForUpdate()->findOrFail($lockedAttempt->deposit_id);
            if ($deposit !== null && $deposit->status !== DepositStatus::Due) {
                throw new DomainException('The provider payment cannot be applied because the deposit is no longer due.');
            }
            $payment = Payment::query()->create([
                'reservation_id' => $reservation->id,
                'status' => PaymentStatus::Succeeded,
                'method' => 'mercado_pago_checkout_pro',
                'origin' => PaymentOrigin::Provider,
                'provider' => $lockedAttempt->provider,
                'provider_reference' => $providerPayment->id,
                'currency' => $request->source_currency,
                'amount_minor' => $request->source_amount_minor,
                'processed_at' => now(),
                'reconciled_at' => now(),
                'metadata' => [
                    'payment_attempt_id' => $lockedAttempt->id,
                    'external_reference' => $lockedAttempt->external_reference,
                    'charge_amount_minor' => $lockedAttempt->charge_amount_minor,
                    'charge_currency' => $lockedAttempt->charge_currency,
                    'conversion_snapshot' => $lockedAttempt->conversion_snapshot,
                ],
            ]);
            $this->folio->postPayment($payment->load('reservation'), null);
            if ($deposit !== null) {
                $deposit->update(['payment_id' => $payment->id, 'status' => DepositStatus::Paid, 'paid_at' => now()]);
            }
            $request->update(['payment_id' => $payment->id, 'state' => 'paid', 'paid_at' => now()]);
            $this->outbox->record('payment', $payment->id, 'payment.succeeded', [
                'payment_id' => $payment->id,
                'reservation_id' => $reservation->id,
                'deposit_id' => $deposit?->id,
                'amount_minor' => $payment->amount_minor,
                'origin' => 'provider',
            ]);

            return $payment->fresh(['reservation', 'deposits']);
        }, 3);
    }

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
                'origin' => PaymentOrigin::Manual,
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
            if ($locked->origin === PaymentOrigin::Provider) {
                throw new DomainException('Provider-origin payments can only be reconciled through the provider workflow.');
            }
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
            Reservation::query()->lockForUpdate()->findOrFail($payment->reservation_id);
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($locked->origin === PaymentOrigin::Provider) {
                throw new DomainException('Provider-origin payments must be refunded through the provider workflow.');
            }
            if ($locked->status === PaymentStatus::Reversed) {
                return $locked;
            }
            if ($locked->status !== PaymentStatus::Succeeded) {
                throw new DomainException('Only reconciled payments may be reversed.');
            }

            $refund = ReservationChange::query()
                ->where('reservation_id', $locked->reservation_id)
                ->where('metadata->payment_id', $locked->id)
                ->where(function ($query): void {
                    $query->where(fn ($requested) => $requested
                        ->where('type', 'refund_requested')
                        ->where('status', 'requested'))
                        ->orWhere(fn ($completed) => $completed
                            ->where('type', 'refund_completed')
                            ->where('status', 'completed'));
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->first();
            if ($refund !== null) {
                throw new DomainException('This payment has an open or completed refund. Use a dedicated correction command for any remaining reversible amount.');
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
