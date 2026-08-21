<?php

namespace App\Services\Payments;

use App\Data\Payments\ProviderOrder;
use App\Data\Payments\ProviderPayment;
use App\Data\Payments\ProviderPaymentApplication;
use App\Enums\PaymentAttemptState;
use App\Enums\PaymentChannel;
use App\Exceptions\CommercialWorkflowException as DomainException;
use App\Models\PaymentAttempt;
use App\Models\PaymentTerminal;
use App\Models\ProviderPosLocation;
use App\Services\PaymentService;
use Illuminate\Support\Facades\DB;

final class ApplyMercadoPagoOrder
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly RecordSettlementRevision $settlements,
    ) {}

    public function handle(PaymentAttempt $attempt, ProviderOrder $order): PaymentAttempt
    {
        try {
            $locked = DB::transaction(function () use ($attempt, $order): PaymentAttempt {
                $locked = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
                $this->assertMatches($locked, $order);
                if ($locked->state === PaymentAttemptState::Mismatched) {
                    $locked->update([
                        'provider_status' => $order->status,
                        'provider_status_detail' => $order->statusDetail,
                        'provider_order_created_at' => $order->createdAt ?? $locked->provider_order_created_at,
                        'provider_order_updated_at' => $order->updatedAt ?? $locked->provider_order_updated_at,
                        'last_checked_at' => now(),
                    ]);

                    return $locked;
                }
                $state = $this->state($order);
                if ($state === null) {
                    throw new DomainException('Unknown or invalid Mercado Pago Orders state; left unapplied for Finance.');
                }
                $lateApproval = $order->status === 'processed' && in_array($locked->state, [
                    PaymentAttemptState::Cancelled, PaymentAttemptState::Expired, PaymentAttemptState::Failed,
                ], true);
                if ($lateApproval) {
                    $locked->update([
                        'state' => PaymentAttemptState::Mismatched,
                        'provider_order_id' => $order->id,
                        'provider_transaction_id' => $order->payments[0]->id,
                        'provider_status' => $order->status,
                        'provider_status_detail' => $order->statusDetail,
                        'provider_order_created_at' => $order->createdAt ?? $locked->provider_order_created_at,
                        'provider_order_updated_at' => $order->updatedAt ?? $locked->provider_order_updated_at,
                        'last_error' => 'Late provider approval after cancellation/expiry/failure requires Finance reconciliation and was not applied to a replacement request.',
                        'last_processed_at' => now(),
                    ]);

                    return $locked;
                }
                if ($locked->state === PaymentAttemptState::Approved && ! in_array($order->status, ['processed', 'refunded'], true)) {
                    $locked->update([
                        'state' => PaymentAttemptState::Mismatched,
                        'provider_status' => $order->status,
                        'provider_status_detail' => $order->statusDetail,
                        'provider_order_created_at' => $order->createdAt ?? $locked->provider_order_created_at,
                        'provider_order_updated_at' => $order->updatedAt ?? $locked->provider_order_updated_at,
                        'last_error' => 'Authoritative Orders state regressed after local payment application; Finance reconciliation is required.',
                        'last_checked_at' => now(),
                        'last_processed_at' => now(),
                    ]);

                    return $locked;
                }
                if ($locked->state === PaymentAttemptState::ActionRequired
                    && in_array($order->status, ['created', 'at_terminal', 'action_required'], true)) {
                    $locked->update([
                        'provider_status' => $order->status,
                        'provider_status_detail' => $order->statusDetail,
                        'provider_order_created_at' => $order->createdAt ?? $locked->provider_order_created_at,
                        'provider_order_updated_at' => $order->updatedAt ?? $locked->provider_order_updated_at,
                        'last_checked_at' => now(),
                        'last_processed_at' => now(),
                    ]);

                    return $locked;
                }
                $terminal = in_array($order->status, ['processed', 'failed', 'canceled', 'expired', 'refunded'], true);
                $locked->update([
                    'state' => $state,
                    'provider_order_id' => $order->id,
                    'provider_transaction_id' => $order->payments[0]->id,
                    'provider_order_type' => $order->type,
                    'provider_status' => $order->status,
                    'provider_status_detail' => $order->statusDetail,
                    'provider_order_created_at' => $order->createdAt ?? $locked->provider_order_created_at,
                    'provider_order_updated_at' => $order->updatedAt ?? $locked->provider_order_updated_at,
                    'qr_mode' => $order->qrMode ?? $locked->qr_mode,
                    'qr_data_ciphertext' => $terminal ? null : $order->qrData,
                    'qr_data_checksum' => $order->qrData === null ? $locked->qr_data_checksum : hash('sha256', $order->qrData),
                    'queued_at' => $order->status === 'created' ? ($locked->queued_at ?? now()) : $locked->queued_at,
                    'at_terminal_at' => $order->status === 'at_terminal' ? ($locked->at_terminal_at ?? now()) : $locked->at_terminal_at,
                    'action_required_at' => $order->status === 'action_required' ? ($locked->action_required_at ?? now()) : $locked->action_required_at,
                    'canceled_at' => $order->status === 'canceled' ? ($locked->canceled_at ?? now()) : $locked->canceled_at,
                    'last_checked_at' => now(),
                    'last_processed_at' => now(),
                    'last_error' => $order->status === 'action_required' ? 'Provider action is required; check the Point terminal and Finance queue.' : null,
                ]);

                return $locked;
            }, 3);
        } catch (DomainException $exception) {
            PaymentAttempt::query()->whereKey($attempt->id)->update([
                'state' => PaymentAttemptState::Mismatched,
                'last_error' => $exception->getMessage(),
                'last_processed_at' => now(),
            ]);
            throw $exception;
        }

        if ($order->status !== 'processed' || $locked->state === PaymentAttemptState::Mismatched) {
            return $locked->fresh();
        }
        $transaction = $order->payments[0];
        if ($transaction->status !== 'processed' || $transaction->statusDetail !== 'accredited') {
            $locked->update(['state' => PaymentAttemptState::Mismatched, 'last_error' => 'Processed order lacks a processed/accredited payment transaction.']);

            return $locked->fresh();
        }
        try {
            $application = new ProviderPaymentApplication(
                $transaction->id,
                $order->providerAccount,
                $order->type === 'point' ? PaymentChannel::IntegratedTerminal : PaymentChannel::Qr,
                $order->type === 'point' ? 'card' : 'provider',
                $order->externalReference,
                $order->amountMinor,
                $order->currency,
                $order->id,
            );
            $this->payments->recordProvider($locked->fresh(), $application);
            $this->settlements->handle($locked->fresh(), new ProviderPayment(
                $transaction->id,
                $order->externalReference,
                'approved',
                $transaction->statusDetail,
                $order->amountMinor,
                $order->currency,
                $order->providerAccount,
                ['gross_minor' => $order->amountMinor, 'fee_minor' => 0, 'net_minor' => $order->amountMinor, 'fact_source' => 'orders_lookup'],
            ));
            $locked->update(['state' => PaymentAttemptState::Approved, 'last_error' => null]);
            $target = $locked->payment_terminal_id !== null
                ? PaymentTerminal::query()->find($locked->payment_terminal_id)
                : ProviderPosLocation::query()->find($locked->provider_pos_location_id);
            $target?->update(['last_successful_order_at' => now(), 'health_state' => 'healthy', 'last_error' => null]);
        } catch (DomainException $exception) {
            $locked->update(['state' => PaymentAttemptState::Mismatched, 'last_error' => $exception->getMessage()]);
        }

        return $locked->fresh();
    }

    private function assertMatches(PaymentAttempt $attempt, ProviderOrder $order): void
    {
        if ($order->payments === []) {
            throw new DomainException('Mercado Pago order lacks its authoritative payment transaction identity.');
        }
        $expectedType = $attempt->channel === PaymentChannel::IntegratedTerminal->value ? 'point' : 'qr';
        $expectedTarget = $expectedType === 'point'
            ? PaymentTerminal::query()->find($attempt->payment_terminal_id)?->provider_terminal_id
            : ProviderPosLocation::query()->find($attempt->provider_pos_location_id)?->external_pos_id;
        $actualTarget = $expectedType === 'point' ? $order->terminalId : $order->externalPosId;
        if ($order->providerAccount !== $attempt->provider_account || $order->type !== $expectedType
            || $order->externalReference !== $attempt->external_reference || $order->amountMinor !== $attempt->charge_amount_minor
            || $order->currency !== $attempt->charge_currency || $expectedTarget === null || $actualTarget !== $expectedTarget
            || ($attempt->provider_order_id !== null && $attempt->provider_order_id !== $order->id)) {
            throw new DomainException('Mercado Pago order account/channel/reference/device/POS/money identity mismatch.');
        }
    }

    private function state(ProviderOrder $order): ?PaymentAttemptState
    {
        if ($order->type === 'qr' && $order->status === 'failed') {
            return null;
        }

        return match ($order->status) {
            'created' => PaymentAttemptState::Queued,
            'at_terminal' => PaymentAttemptState::AtTerminal,
            'action_required' => PaymentAttemptState::ActionRequired,
            'processed' => PaymentAttemptState::Processing,
            'failed' => PaymentAttemptState::Failed,
            'canceled' => PaymentAttemptState::Cancelled,
            'expired' => PaymentAttemptState::Expired,
            'refunded' => PaymentAttemptState::Approved,
            default => null,
        };
    }
}
