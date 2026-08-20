<?php

namespace App\Services\Payments;

use App\Data\Payments\IssuedPaymentRequest;
use App\Enums\DepositStatus;
use App\Enums\PaymentRequestPurpose;
use App\Enums\PaymentRequestState;
use App\Enums\ReservationStatus;
use App\Exceptions\CommercialWorkflowException as DomainException;
use App\Models\Deposit;
use App\Models\PaymentRequest;
use App\Models\Reservation;
use App\Services\Automation\OutboxRecorder;
use App\Services\Communications\QueuePaymentRequestCommunication;
use App\Services\FolioService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class IssuePaymentRequest
{
    public function __construct(
        private readonly FolioService $folio,
        private readonly OutboxRecorder $outbox,
        private readonly QueuePaymentRequestCommunication $communications,
    ) {}

    public function handle(
        Reservation $reservation,
        PaymentRequestPurpose $purpose,
        ?string $depositId,
        ?int $authorizedAmountMinor,
        ?int $actorId,
        mixed $expiresAt = null,
    ): IssuedPaymentRequest {
        return DB::transaction(function () use ($reservation, $purpose, $depositId, $authorizedAmountMinor, $actorId, $expiresAt): IssuedPaymentRequest {
            $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            if (! in_array($locked->status, [ReservationStatus::Confirmed, ReservationStatus::CheckedIn], true)) {
                throw new DomainException('Payment requests require a confirmed or checked-in reservation.');
            }
            $outstanding = max(0, $this->folio->summary($locked)['balance_minor']);
            $deposit = $depositId === null ? null : Deposit::query()->lockForUpdate()->findOrFail($depositId);
            if ($deposit !== null && ($deposit->reservation_id !== $locked->id || $deposit->status !== DepositStatus::Due)) {
                throw new DomainException('The selected deposit is not due on this reservation.');
            }

            if ($purpose === PaymentRequestPurpose::Deposit && $deposit === null) {
                throw new DomainException('A due deposit is required.');
            }
            $amount = match ($purpose) {
                PaymentRequestPurpose::Deposit => $deposit->amount_minor,
                PaymentRequestPurpose::Balance, PaymentRequestPurpose::FullOutstanding => $outstanding,
                PaymentRequestPurpose::AuthorizedPartial => $authorizedAmountMinor ?? 0,
            };
            if ($amount <= 0 || $amount > $outstanding) {
                throw new DomainException('The payment-request amount must be positive and no greater than the outstanding balance.');
            }
            if ($deposit !== null && $deposit->currency !== $locked->currency) {
                throw new DomainException('The deposit currency does not match the reservation.');
            }

            $snapshot = [
                'reservation_revision' => $locked->revision,
                'booked_total_minor' => $locked->total_minor,
                'outstanding_minor' => $outstanding,
                'deposit_id' => $deposit?->id,
                'deposit_amount_minor' => $deposit?->amount_minor,
                'calculated_at' => now()->toIso8601String(),
            ];
            $token = Str::random(64);
            $request = PaymentRequest::query()->create([
                'property_id' => $locked->property_id,
                'reservation_id' => $locked->id,
                'deposit_id' => $deposit?->id,
                'created_by' => $actorId,
                'public_id' => (string) Str::uuid(),
                'access_token_hash' => hash('sha256', $token),
                'initiation_mode' => 'guest_link',
                'purpose' => $purpose,
                'state' => PaymentRequestState::Open,
                'source_amount_minor' => $amount,
                'source_currency' => $locked->currency,
                'charge_currency' => $locked->currency === 'USD' ? null : $locked->currency,
                'calculation_snapshot' => $snapshot,
                'calculation_checksum' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
                'expires_at' => $expiresAt ?? now()->addDays(3),
            ]);
            $this->outbox->record('payment_request', $request->id, 'payment_request.issued', [
                'payment_request_id' => $request->id,
                'reservation_id' => $locked->id,
                'amount_minor' => $amount,
                'currency' => $locked->currency,
            ]);
            $this->communications->handle($request, $token, $actorId);

            return new IssuedPaymentRequest($request, $token);
        }, 3);
    }
}
