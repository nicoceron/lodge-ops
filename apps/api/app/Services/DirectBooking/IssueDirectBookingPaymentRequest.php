<?php

namespace App\Services\DirectBooking;

use App\Enums\DepositStatus;
use App\Enums\DirectBookingErrorCode;
use App\Enums\DirectBookingOrderState;
use App\Enums\DirectBookingTransitionAuthority;
use App\Enums\PaymentRequestPurpose;
use App\Enums\PaymentRequestState;
use App\Enums\ReservationStatus;
use App\Exceptions\DirectBookingContractException;
use App\Models\BookingQuote;
use App\Models\Deposit;
use App\Models\DirectBookingOrder;
use App\Models\PaymentRequest;
use App\Models\Reservation;
use App\Services\Automation\OutboxRecorder;
use App\Services\Documents\CanonicalJson;
use App\Services\FolioService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Narrow public-booking issuance seam. The staff IssuePaymentRequest rule remains unchanged.
 */
final class IssueDirectBookingPaymentRequest
{
    public function __construct(
        private readonly FolioService $folio,
        private readonly OutboxRecorder $outbox,
        private readonly DirectBookingStateMachine $states,
        private readonly CanonicalJson $canonicalJson,
    ) {}

    /** @return array{request: PaymentRequest, token: ?string, replayed: bool} */
    public function handle(DirectBookingOrder $order, int $expectedVersion, string $retryIdentity): array
    {
        return DB::transaction(function () use ($order, $expectedVersion, $retryIdentity): array {
            $locked = DirectBookingOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->payment_request_id !== null) {
                $request = $locked->paymentRequest()->lockForUpdate()->firstOrFail();
                $result = $this->states->transition(
                    $locked,
                    DirectBookingOrderState::PaymentPending,
                    DirectBookingTransitionAuthority::PaymentOrchestrator,
                    $expectedVersion,
                    $retryIdentity,
                    ['payment_request_reference' => $request->public_id],
                );

                return ['request' => $request, 'token' => null, 'replayed' => $result->replayed];
            }
            if ($locked->state !== DirectBookingOrderState::Held || $locked->state_version !== $expectedVersion) {
                throw new DirectBookingContractException(DirectBookingErrorCode::Conflict, 'A current held order is required to issue direct-booking payment.');
            }

            $quote = BookingQuote::query()->lockForUpdate()->findOrFail($locked->booking_quote_id);
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($locked->reservation_id);
            if ($quote->property_id !== $locked->property_id || $reservation->property_id !== $locked->property_id
                || $reservation->booking_quote_id !== $quote->id || $reservation->status !== ReservationStatus::Hold
                || $reservation->hold_expires_at?->isFuture() !== true
                || $locked->hold_expires_at === null || ! $locked->hold_expires_at->equalTo($reservation->hold_expires_at)
                || ! hash_equals($quote->checksum, (string) data_get($reservation->price_snapshot, 'checksum'))
                || $reservation->deposit_policy_snapshot !== $quote->deposit_policy_snapshot) {
                throw new DirectBookingContractException(DirectBookingErrorCode::HoldExpired, 'The quote, deposit schedule, and authoritative hold no longer agree.');
            }

            [$scheduleType, $depositAmount] = $this->quotedDeposit($quote);
            if ($depositAmount <= 0 || $depositAmount > $reservation->total_minor) {
                throw new DirectBookingContractException(DirectBookingErrorCode::Validation, 'The quoted deposit is not payable.', 422);
            }
            $deposit = Deposit::query()->where('reservation_id', $reservation->id)
                ->where('schedule_type', $scheduleType)->lockForUpdate()->first();
            if ($deposit === null) {
                $deposit = Deposit::query()->create([
                    'reservation_id' => $reservation->id,
                    'schedule_type' => $scheduleType,
                    'status' => DepositStatus::Due,
                    'currency' => $quote->currency,
                    'amount_minor' => $depositAmount,
                    'due_at' => now(),
                ]);
            } elseif ($deposit->status !== DepositStatus::Due || $deposit->currency !== $quote->currency
                || $deposit->amount_minor !== $depositAmount) {
                throw new DirectBookingContractException(DirectBookingErrorCode::Conflict, 'The reservation deposit no longer matches the immutable quote.');
            }

            $outstanding = max(0, $this->folio->summary($reservation)['balance_minor']);
            if ($depositAmount > $outstanding) {
                throw new DirectBookingContractException(DirectBookingErrorCode::Conflict, 'The quoted deposit exceeds the authoritative outstanding balance.');
            }
            $snapshot = [
                'channel' => 'direct_booking',
                'order_reference' => $locked->public_reference,
                'quote_id' => $quote->id,
                'quote_checksum' => $quote->checksum,
                'reservation_revision' => $reservation->revision,
                'deposit_id' => $deposit->id,
                'deposit_schedule_type' => $scheduleType,
                'deposit_amount_minor' => $depositAmount,
                'deposit_policy_snapshot' => $quote->deposit_policy_snapshot,
                'authoritative_hold_expires_at' => $reservation->hold_expires_at->toIso8601String(),
            ];
            $token = Str::random(64);
            $request = PaymentRequest::query()->create([
                'property_id' => $locked->property_id,
                'reservation_id' => $reservation->id,
                'deposit_id' => $deposit->id,
                'created_by' => null,
                'public_id' => (string) Str::uuid(),
                'access_token_hash' => hash('sha256', $token),
                'initiation_mode' => 'direct_booking',
                'purpose' => PaymentRequestPurpose::Deposit,
                'state' => PaymentRequestState::Open,
                'source_amount_minor' => $depositAmount,
                'source_currency' => $quote->currency,
                'charge_currency' => $quote->currency,
                'calculation_snapshot' => $snapshot,
                'calculation_checksum' => $this->canonicalJson->checksum($snapshot),
                'expires_at' => $reservation->hold_expires_at,
            ]);
            $locked->forceFill([
                'payment_request_id' => $request->id,
                'checkout_expires_at' => $reservation->hold_expires_at,
            ])->save();
            $result = $this->states->transition(
                $locked,
                DirectBookingOrderState::PaymentPending,
                DirectBookingTransitionAuthority::PaymentOrchestrator,
                $expectedVersion,
                $retryIdentity,
                ['payment_request_reference' => $request->public_id],
            );
            $this->outbox->record('payment_request', $request->id, 'payment_request.issued', [
                'payment_request_id' => $request->id,
                'reservation_id' => $reservation->id,
                'amount_minor' => $depositAmount,
                'currency' => $quote->currency,
            ]);

            return ['request' => $request, 'token' => $token, 'replayed' => $result->replayed];
        }, 3);
    }

    /** @return array{string, int} */
    private function quotedDeposit(BookingQuote $quote): array
    {
        $policy = $quote->deposit_policy_snapshot ?? [];
        $scheduleType = $policy === [] ? 'deposit_50' : 'deposit';
        $amount = match ($policy['requirement_type'] ?? 'percentage') {
            'fixed' => min($quote->total_minor, (int) ($policy['fixed_amount_minor'] ?? 0)),
            default => intdiv(($quote->total_minor * (int) ($policy['percentage_basis_points'] ?? 5000)) + 9999, 10000),
        };

        return [$scheduleType, $amount];
    }
}
