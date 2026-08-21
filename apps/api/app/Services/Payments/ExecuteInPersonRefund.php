<?php

namespace App\Services\Payments;

use App\Contracts\Payments\InPersonPaymentGatewayFactory;
use App\Data\Payments\ProviderOrderRefundRequest;
use App\Enums\PaymentChannel;
use App\Enums\PaymentOrigin;
use App\Enums\ProviderRefundState;
use App\Exceptions\CommercialWorkflowException as DomainException;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\ProviderRefund;
use App\Models\Reservation;
use App\Models\ReservationChange;
use App\Services\CompleteRefund;
use App\Services\Documents\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ExecuteInPersonRefund
{
    public function __construct(
        private readonly InPersonPaymentGatewayFactory $gateways,
        private readonly CompleteRefund $complete,
        private readonly CanonicalJson $canonical,
    ) {}

    public function handle(ReservationChange $request, ?int $actorId): ProviderRefund
    {
        $execution = DB::transaction(function () use ($request): ProviderRefund {
            $snapshot = ReservationChange::query()->findOrFail($request->id);
            Reservation::query()->lockForUpdate()->findOrFail($snapshot->reservation_id);
            $payment = Payment::query()->lockForUpdate()->findOrFail(data_get($snapshot->metadata, 'payment_id'));
            $lockedRequest = ReservationChange::query()->lockForUpdate()->findOrFail($snapshot->id);
            if ($payment->origin !== PaymentOrigin::Provider || ! in_array($payment->channel, [PaymentChannel::IntegratedTerminal, PaymentChannel::Qr], true)
                || $lockedRequest->type !== 'refund_requested' || $lockedRequest->status !== 'requested') {
                throw new DomainException('Orders refund execution requires an open refund request for a Point/QR provider payment.');
            }
            $attempt = PaymentAttempt::query()
                ->where('provider', $payment->provider)
                ->where('environment', $payment->environment)
                ->where('provider_account', $payment->provider_account)
                ->where('provider_transaction_id', $payment->provider_reference)
                ->lockForUpdate()->firstOrFail();
            if ($attempt->provider_order_id === null || $attempt->provider_transaction_id === null) {
                throw new DomainException('The source payment lacks its authoritative Orders identities.');
            }
            $existing = ProviderRefund::query()->where('reservation_change_id', $lockedRequest->id)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }
            $idempotencyKey = (string) Str::uuid();
            $body = [
                'provider_order_id' => $attempt->provider_order_id,
                'provider_transaction_id' => $attempt->provider_transaction_id,
                'amount_minor' => $lockedRequest->amount_minor === $payment->amount_minor ? null : $lockedRequest->amount_minor,
                'currency' => $payment->currency,
            ];

            return ProviderRefund::query()->create([
                'payment_id' => $payment->id,
                'reservation_change_id' => $lockedRequest->id,
                'property_id' => $payment->reservation->property_id,
                'integration_connection_id' => $attempt->integration_connection_id,
                'provider' => $attempt->provider,
                'environment' => $attempt->environment,
                'provider_account' => $attempt->provider_account,
                'source_amount_minor' => $lockedRequest->amount_minor,
                'source_currency' => $payment->currency,
                'charge_amount_minor' => $lockedRequest->amount_minor,
                'charge_currency' => $payment->currency,
                'idempotency_key' => $idempotencyKey,
                'operation_checksum' => $this->canonical->checksum($body),
                'provider_payment_id' => $payment->provider_reference,
                'provider_resource_type' => 'order',
                'provider_order_id' => $attempt->provider_order_id,
                'provider_transaction_id' => $attempt->provider_transaction_id,
                'state' => ProviderRefundState::Requested,
            ]);
        }, 3);
        if ($execution->state === ProviderRefundState::Succeeded) {
            return $execution;
        }
        $attempt = PaymentAttempt::query()
            ->where('provider_order_id', $execution->provider_order_id)
            ->where('provider_transaction_id', $execution->provider_transaction_id)
            ->firstOrFail();
        $gateway = $this->gateways->for($attempt->integrationConnection);
        $order = $gateway->fetchOrder($execution->provider_order_id);
        if ($execution->provider_refund_id !== null) {
            $authoritative = collect($order->refunds)->first(fn ($refund): bool => $refund->id === $execution->provider_refund_id);
            if ($authoritative !== null && $authoritative->providerTransactionId === $execution->provider_transaction_id
                && $authoritative->amountMinor === $execution->charge_amount_minor
                && in_array($authoritative->status, ['processed', 'approved', 'succeeded', 'refunded'], true)) {
                $this->complete->handle($execution->reservationChange, $authoritative->id, $actorId);
                $execution->update([
                    'state' => ProviderRefundState::Succeeded,
                    'provider_action_required' => false,
                    'provider_reason' => $authoritative->statusDetail,
                    'succeeded_at' => now(),
                    'last_error' => null,
                ]);

                return $execution->fresh();
            }
        }
        if ($order->status !== 'processed' && $order->status !== 'refunded') {
            $execution->update(['state' => ProviderRefundState::Mismatched, 'provider_reason' => 'Only an authoritative processed order may be refunded.']);

            return $execution->fresh();
        }
        $paidAt = $attempt->provider_order_updated_at ?? $attempt->last_processed_at ?? $attempt->created_at;
        $maximumDays = $attempt->channel === PaymentChannel::IntegratedTerminal->value ? 90 : 360;
        if ($paidAt === null || $paidAt->lt(now()->subDays($maximumDays))) {
            $reason = $attempt->channel === PaymentChannel::IntegratedTerminal->value
                ? 'Point refund exceeds the documented 90-day limit.'
                : 'QR refund exceeds the maximum 360-day limit documented by the migration/error references; current processing narrative also reports 180 days.';
            $execution->update(['state' => ProviderRefundState::Failed, 'provider_reason' => $reason, 'last_error' => $reason]);

            return $execution->fresh();
        }
        $execution->update(['state' => ProviderRefundState::Processing, 'attempt_count' => $execution->attempt_count + 1, 'last_attempted_at' => now()]);
        try {
            $remote = $gateway->refundOrder(new ProviderOrderRefundRequest(
                $execution->provider_order_id,
                $execution->provider_transaction_id,
                $execution->idempotency_key,
                $execution->operation_checksum,
                $execution->charge_currency,
                $execution->charge_amount_minor === $attempt->charge_amount_minor ? null : $execution->charge_amount_minor,
            ));
            if ($remote->providerOrderId !== $execution->provider_order_id || $remote->providerTransactionId !== $execution->provider_transaction_id
                || $remote->amountMinor !== $execution->charge_amount_minor || strtoupper($remote->currency) !== strtoupper($execution->charge_currency)) {
                $execution->update(['state' => ProviderRefundState::Mismatched, 'last_error' => 'Orders refund identity, amount, or currency mismatch.']);

                return $execution->fresh();
            }
            $execution->update([
                'provider_refund_id' => $remote->id,
                'response_checksum' => $this->canonical->checksum([
                    'id' => $remote->id, 'order_id' => $remote->providerOrderId, 'transaction_id' => $remote->providerTransactionId,
                    'status' => $remote->status, 'amount_minor' => $remote->amountMinor, 'currency' => $remote->currency,
                ]),
                'provider_reason' => $remote->statusDetail,
                'provider_action_required' => in_array($remote->status, ['processing', 'action_required'], true),
                'last_error' => in_array($remote->status, ['processing', 'action_required'], true)
                    ? 'Provider refund remains processing/action-required; Point may require card/terminal action.' : null,
            ]);
            if (! in_array($remote->status, ['processed', 'approved', 'succeeded', 'refunded'], true)) {
                return $execution->fresh();
            }
            $this->complete->handle($execution->reservationChange, $remote->id, $actorId);
            $execution->update(['state' => ProviderRefundState::Succeeded, 'provider_action_required' => false, 'succeeded_at' => now(), 'last_error' => null]);

            return $execution->fresh();
        } catch (\Throwable $exception) {
            $execution->update(['provider_reason' => Str::limit($exception->getMessage(), 500), 'last_error' => Str::limit($exception->getMessage(), 500)]);
            throw $exception;
        }
    }
}
