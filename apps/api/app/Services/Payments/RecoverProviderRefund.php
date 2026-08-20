<?php

namespace App\Services\Payments;

use App\Contracts\Payments\PaymentGatewayFactory;
use App\Enums\ProviderRefundState;
use App\Exceptions\CommercialWorkflowException as DomainException;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\ProviderRefund;
use App\Models\Reservation;
use App\Models\ReservationChange;
use App\Services\CompleteRefund;
use Illuminate\Support\Facades\DB;

final class RecoverProviderRefund
{
    public function __construct(
        private readonly PaymentGatewayFactory $gateways,
        private readonly CompleteRefund $completeRefund,
    ) {}

    public function handle(ProviderRefund $refund, string $providerRefundId, ?int $actorId): ProviderRefund
    {
        $providerRefundId = trim($providerRefundId);
        if ($providerRefundId === '') {
            throw new DomainException('A provider refund identifier is required for authoritative recovery.');
        }

        [$snapshot, $shouldFetch] = DB::transaction(function () use ($refund, $providerRefundId): array {
            $row = ProviderRefund::query()->findOrFail($refund->id);
            Reservation::query()->lockForUpdate()->findOrFail($row->reservationChange->reservation_id);
            Payment::query()->lockForUpdate()->findOrFail($row->payment_id);
            ReservationChange::query()->lockForUpdate()->findOrFail($row->reservation_change_id);
            $locked = ProviderRefund::query()->lockForUpdate()->findOrFail($row->id);
            if ($locked->provider_refund_id !== null && ! hash_equals($locked->provider_refund_id, $providerRefundId)) {
                throw new DomainException('The supplied provider refund identity conflicts with the recorded recovery identity.');
            }
            if ($locked->state === ProviderRefundState::Succeeded
                && $locked->reservationChange->events()->where('type', 'refund_completed')->exists()) {
                return [$locked->fresh(), false];
            }
            $locked->update([
                'provider_refund_id' => $providerRefundId,
                'state' => ProviderRefundState::Processing,
                'attempt_count' => $locked->attempt_count + 1,
                'last_attempted_at' => now(),
            ]);

            return [$locked->fresh(), true];
        }, 3);
        if (! $shouldFetch) {
            return $snapshot;
        }

        $attempt = PaymentAttempt::query()
            ->where('provider', $snapshot->provider)
            ->where('environment', $snapshot->environment)
            ->where('provider_account', $snapshot->provider_account)
            ->where('provider_payment_id', $snapshot->provider_payment_id)
            ->firstOrFail();
        $remote = $this->gateways->for($attempt->integrationConnection)
            ->fetchRefund($snapshot->provider_payment_id, $providerRefundId);

        if ($remote->providerPaymentId !== $snapshot->provider_payment_id
            || $remote->id !== $providerRefundId
            || $remote->amountMinor !== $snapshot->charge_amount_minor
            || strtoupper($remote->currency) !== strtoupper($snapshot->charge_currency)
            || $remote->providerAccount === ''
            || $remote->providerAccount !== $snapshot->provider_account
            || ! in_array($remote->status, ['approved', 'success', 'succeeded'], true)) {
            return DB::transaction(function () use ($snapshot): ProviderRefund {
                $row = ProviderRefund::query()->findOrFail($snapshot->id);
                Reservation::query()->lockForUpdate()->findOrFail($row->reservationChange->reservation_id);
                Payment::query()->lockForUpdate()->findOrFail($row->payment_id);
                ReservationChange::query()->lockForUpdate()->findOrFail($row->reservation_change_id);
                $locked = ProviderRefund::query()->lockForUpdate()->findOrFail($row->id);
                if ($locked->state !== ProviderRefundState::Succeeded) {
                    $locked->update([
                        'state' => ProviderRefundState::Mismatched,
                        'last_error' => 'Authoritative provider refund identity, amount, currency, account, or status mismatch.',
                    ]);
                }

                return $locked->fresh();
            }, 3);
        }

        $checksum = hash('sha256', json_encode([
            'id' => $remote->id,
            'payment_id' => $remote->providerPaymentId,
            'status' => $remote->status,
            'amount_minor' => $remote->amountMinor,
            'currency' => $remote->currency,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($snapshot, $providerRefundId, $remote, $actorId, $checksum): ProviderRefund {
            $row = ProviderRefund::query()->findOrFail($snapshot->id);
            Reservation::query()->lockForUpdate()->findOrFail($row->reservationChange->reservation_id);
            Payment::query()->lockForUpdate()->findOrFail($row->payment_id);
            $request = ReservationChange::query()->lockForUpdate()->findOrFail($row->reservation_change_id);
            $locked = ProviderRefund::query()->lockForUpdate()->findOrFail($row->id);
            if ($locked->provider_refund_id !== null && ! hash_equals($locked->provider_refund_id, $providerRefundId)) {
                throw new DomainException('The supplied provider refund identity conflicts with the recorded recovery identity.');
            }
            if ($locked->state === ProviderRefundState::Succeeded
                && $request->events()->where('type', 'refund_completed')->exists()) {
                return $locked->fresh();
            }
            $this->completeRefund->handle($request, $remote->id, $actorId);
            $locked->update([
                'state' => ProviderRefundState::Succeeded,
                'response_checksum' => $checksum,
                'last_error' => null,
                'succeeded_at' => now(),
            ]);

            return $locked->fresh();
        }, 3);
    }
}
