<?php

namespace App\Services;

use App\Data\Payments\ProviderPayment;
use App\Enums\DepositStatus;
use App\Enums\DocumentKind;
use App\Enums\FolioStatus;
use App\Enums\PaymentChannel;
use App\Enums\PaymentEntryMode;
use App\Enums\PaymentOrigin;
use App\Enums\PaymentRequestState;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\CommercialWorkflowException as DomainException;
use App\Models\Deposit;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentRequest;
use App\Models\Reservation;
use App\Models\ReservationChange;
use App\Models\Tenant;
use App\Services\Automation\OutboxRecorder;
use App\Services\Documents\RequestDocumentGeneration;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class PaymentService
{
    public function __construct(
        private readonly FolioService $folio,
        private readonly OutboxRecorder $outbox,
        private readonly RequestDocumentGeneration $documents,
    ) {}

    public function recordProvider(PaymentAttempt $attempt, ProviderPayment $providerPayment): Payment
    {
        return DB::transaction(function () use ($attempt, $providerPayment): Payment {
            Tenant::query()->whereKey($attempt->tenant_id)->lockForUpdate()->firstOrFail();
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($attempt->reservation_id);
            $request = PaymentRequest::query()->lockForUpdate()->findOrFail($attempt->payment_request_id);
            $lockedAttempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if ($providerPayment->providerAccount === '' || $providerPayment->providerAccount !== $lockedAttempt->provider_account) {
                throw new DomainException('The provider payment account does not match the payment attempt.');
            }
            $existing = Payment::query()->where('provider', $lockedAttempt->provider)
                ->where('environment', $lockedAttempt->environment)
                ->where('provider_account', $lockedAttempt->provider_account)
                ->where('provider_reference', $providerPayment->id)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->reservation_id !== $reservation->id
                    || (string) data_get($existing->metadata, 'payment_attempt_id') !== $lockedAttempt->id) {
                    throw new DomainException('The provider payment identity is already attached to a different reservation or attempt.');
                }
                if ($request->payment_id === $existing->id) {
                    $this->requestProviderReceipt($reservation, $existing);

                    return $existing;
                }
                if (! in_array($request->state, [PaymentRequestState::Open, PaymentRequestState::Processing], true)
                    || $request->expires_at->isPast() || $request->payment_id !== null) {
                    throw new DomainException('The provider payment belongs to a stale, expired, revoked, superseded, or already-paid request and requires Finance review/refund.');
                }
                $request->update(['payment_id' => $existing->id, 'state' => 'paid', 'paid_at' => $existing->processed_at ?? now()]);
                $this->requestProviderReceipt($reservation, $existing);

                return $existing;
            }
            if (! in_array($request->state, [PaymentRequestState::Open, PaymentRequestState::Processing], true)
                || $request->expires_at->isPast()) {
                throw new DomainException('The provider payment belongs to a stale, expired, revoked, superseded, or already-paid request and requires Finance review/refund.');
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
                'channel' => PaymentChannel::OnlineCheckout,
                'entry_mode' => PaymentEntryMode::ProviderReported,
                'origin' => PaymentOrigin::Provider,
                'provider' => $lockedAttempt->provider,
                'environment' => $lockedAttempt->environment,
                'provider_account' => $lockedAttempt->provider_account,
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
            $this->requestProviderReceipt($reservation, $payment);

            return $payment->fresh(['reservation', 'deposits']);
        }, 3);
    }

    public function recordProviderNeedsReview(PaymentAttempt $attempt, ProviderPayment $providerPayment, string $reason): Payment
    {
        return DB::transaction(function () use ($attempt, $providerPayment, $reason): Payment {
            Tenant::query()->whereKey($attempt->tenant_id)->lockForUpdate()->firstOrFail();
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($attempt->reservation_id);
            $request = PaymentRequest::query()->lockForUpdate()->findOrFail($attempt->payment_request_id);
            $lockedAttempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            $providerAccount = trim($providerPayment->providerAccount) ?: $lockedAttempt->provider_account;
            $existing = Payment::query()
                ->where('provider', $lockedAttempt->provider)
                ->where('environment', $lockedAttempt->environment)
                ->where('provider_account', $providerAccount)
                ->where('provider_reference', $providerPayment->id)
                ->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->reservation_id !== $reservation->id
                    || (string) data_get($existing->metadata, 'payment_attempt_id') !== $lockedAttempt->id) {
                    throw new DomainException('The provider payment identity is already attached to a different reservation or attempt.');
                }

                return $existing;
            }
            $sourceAmount = $providerPayment->currency === $request->source_currency
                ? $providerPayment->amountMinor
                : ($providerPayment->currency === $lockedAttempt->charge_currency && $lockedAttempt->charge_amount_minor > 0
                    ? BigDecimal::of($providerPayment->amountMinor)->multipliedBy($lockedAttempt->source_amount_minor)
                        ->dividedBy($lockedAttempt->charge_amount_minor, 0, RoundingMode::HalfUp)->toInt()
                    : $request->source_amount_minor);
            $payment = Payment::query()->create([
                'reservation_id' => $reservation->id,
                'status' => PaymentStatus::Succeeded,
                'method' => 'mercado_pago_checkout_pro',
                'channel' => PaymentChannel::OnlineCheckout,
                'entry_mode' => PaymentEntryMode::ProviderReported,
                'origin' => PaymentOrigin::Provider,
                'provider' => $lockedAttempt->provider,
                'environment' => $lockedAttempt->environment,
                'provider_account' => $providerAccount,
                'provider_reference' => $providerPayment->id,
                'currency' => $request->source_currency,
                'amount_minor' => max(1, $sourceAmount),
                'processed_at' => now(),
                'reconciled_at' => now(),
                'metadata' => [
                    'payment_attempt_id' => $lockedAttempt->id,
                    'external_reference' => $providerPayment->externalReference,
                    'unapplied_direct_booking_funds' => true,
                    'needs_review_reason' => $reason,
                    'actual_charge_amount_minor' => $providerPayment->amountMinor,
                    'actual_charge_currency' => strtoupper($providerPayment->currency),
                    'actual_provider_account' => $providerAccount,
                    'expected_charge_amount_minor' => $lockedAttempt->charge_amount_minor,
                    'expected_charge_currency' => $lockedAttempt->charge_currency,
                ],
            ]);
            $this->folio->postPayment($payment->load('reservation'), null);
            $this->outbox->record('payment', $payment->id, 'payment.needs_review', [
                'payment_id' => $payment->id,
                'reservation_id' => $reservation->id,
                'amount_minor' => $payment->amount_minor,
                'origin' => 'provider',
                'safe_reason_code' => $reason,
            ]);

            return $payment->fresh(['reservation']);
        }, 3);
    }

    private function requestProviderReceipt(Reservation $reservation, Payment $payment): void
    {
        if ($reservation->status === ReservationStatus::Hold
            && $reservation->directBookingOrder()->exists()) {
            return;
        }
        $this->documents->handleSystem(
            $reservation,
            DocumentKind::PaymentReceipt,
            app()->getLocale(),
            'provider-payment-receipt:'.$payment->id,
            $payment,
        );
    }

    public function recordFrontDesk(
        Reservation $reservation,
        PaymentChannel $channel,
        int $amountMinor,
        ?int $actorId,
        ?string $depositId = null,
    ): Payment {
        return DB::transaction(function () use ($reservation, $channel, $amountMinor, $actorId, $depositId): Payment {
            $lockedReservation = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            if (! in_array($channel, [PaymentChannel::BankTransfer, PaymentChannel::Cash, PaymentChannel::ExternalTerminal, PaymentChannel::ManualOther], true)) {
                throw new DomainException('Only staff-recorded tender channels are accepted by the front-desk command.');
            }
            if ($amountMinor <= 0) {
                throw new DomainException('The payment amount must be greater than zero.');
            }
            if ($lockedReservation->folio_status === FolioStatus::Closed
                || in_array($lockedReservation->status, [ReservationStatus::Cancelled, ReservationStatus::NoShow], true)) {
                throw new DomainException('Payments cannot be posted to a closed, cancelled, or no-show reservation.');
            }
            $outstanding = max(0, $this->folio->summary($lockedReservation)['balance_minor']);
            if ($amountMinor > $outstanding) {
                throw new DomainException('The payment exceeds the remaining reservation balance.');
            }
            $deposit = $depositId === null ? null : Deposit::query()->lockForUpdate()->findOrFail($depositId);
            if ($deposit !== null) {
                if ($deposit->reservation_id !== $lockedReservation->id || $deposit->currency !== $lockedReservation->currency || $deposit->status !== DepositStatus::Due) {
                    throw new DomainException('Only a due deposit in the reservation currency may be selected.');
                }
                if ($amountMinor < $deposit->amount_minor) {
                    throw new DomainException('The payment does not cover the selected deposit.');
                }
            }

            $payment = Payment::query()->create([
                'reservation_id' => $lockedReservation->id,
                'status' => PaymentStatus::Succeeded,
                'method' => $channel->legacyMethod(),
                'channel' => $channel,
                'entry_mode' => PaymentEntryMode::StaffRecorded,
                'origin' => PaymentOrigin::Manual,
                'currency' => $lockedReservation->currency,
                'amount_minor' => $amountMinor,
                'processed_at' => now(),
                'reconciled_at' => now(),
                'recorded_by' => $actorId,
                'reconciled_by' => $actorId,
                'metadata' => ['classification_source' => 'front_desk_command'],
            ]);
            $this->folio->postPayment($payment->load('reservation'), $actorId);
            if ($deposit !== null) {
                $deposit->update(['payment_id' => $payment->id, 'status' => DepositStatus::Paid, 'paid_at' => now()]);
            }
            $this->outbox->record('payment', $payment->id, 'payment.succeeded', [
                'payment_id' => $payment->id,
                'reservation_id' => $lockedReservation->id,
                'deposit_id' => $deposit?->id,
                'amount_minor' => $amountMinor,
                'channel' => $channel->value,
                'origin' => PaymentOrigin::Manual->value,
            ]);

            return $payment->fresh(['reservation', 'deposits']);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function recordManual(array $data, ?int $actorId, bool $capture = false): Payment
    {
        return DB::transaction(function () use ($data, $actorId, $capture): Payment {
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($data['reservation_id']);

            $manualProcessor = trim((string) ($data['provider'] ?? '')) ?: null;
            $manualReference = trim((string) ($data['provider_reference'] ?? '')) ?: null;
            if ($manualProcessor !== null && $manualReference !== null) {
                $existing = Payment::query()
                    ->where('metadata->manual_processor_alias', $manualProcessor)
                    ->where('metadata->manual_transaction_reference', $manualReference)
                    ->first();
                if ($existing !== null) {
                    return $existing;
                }
            }

            $payment = Payment::query()->create([
                ...Arr::only($data, [
                    'reservation_id',
                    'method',
                    'evidence_url',
                    'evidence_note',
                    'amount_minor',
                ]),
                'origin' => PaymentOrigin::Manual,
                'channel' => match ($data['method']) {
                    'bank_transfer' => PaymentChannel::BankTransfer,
                    'cash' => PaymentChannel::Cash,
                    'card' => PaymentChannel::ExternalTerminal,
                    default => PaymentChannel::ManualOther,
                },
                'entry_mode' => PaymentEntryMode::StaffRecorded,
                'currency' => $reservation->currency,
                'status' => PaymentStatus::Pending,
                'recorded_by' => $actorId,
                'metadata' => [
                    ...((array) ($data['metadata'] ?? [])),
                    'manual_processor_alias' => $manualProcessor,
                    'manual_transaction_reference' => $manualReference,
                ],
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
            $snapshot = Payment::query()->findOrFail($payment->id);
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($snapshot->reservation_id);
            $locked = Payment::query()->with('reservation')->lockForUpdate()->findOrFail($snapshot->id);
            if ($locked->origin === PaymentOrigin::Provider) {
                throw new DomainException('Provider-origin payments can only be reconciled through the provider workflow.');
            }
            if ($locked->status === PaymentStatus::Succeeded) {
                return $locked;
            }
            if ($locked->status !== PaymentStatus::Pending) {
                throw new DomainException('Only pending payments may be reconciled.');
            }
            $outstanding = max(0, $this->folio->summary($reservation)['balance_minor']);
            if ($locked->amount_minor > $outstanding && PaymentRequest::query()
                ->where('reservation_id', $reservation->id)
                ->whereNotNull('payment_id')
                ->exists()) {
                throw new DomainException('The payment exceeds the remaining reservation balance and requires Finance review.');
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
