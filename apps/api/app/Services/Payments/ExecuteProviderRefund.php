<?php

namespace App\Services\Payments;

use App\Contracts\Payments\PaymentGatewayFactory;
use App\Data\Payments\ProviderRefundRequest;
use App\Enums\PaymentOrigin;
use App\Enums\ProviderRefundState;
use App\Exceptions\CommercialWorkflowException as DomainException;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\ProviderRefund;
use App\Models\Reservation;
use App\Models\ReservationChange;
use App\Services\CompleteRefund;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class ExecuteProviderRefund
{
    public function __construct(private readonly PaymentGatewayFactory $gateways, private readonly CompleteRefund $completeRefund) {}

    public function handle(ReservationChange $request, ?int $actorId): ProviderRefund
    {
        $execution = DB::transaction(function () use ($request): ProviderRefund {
            $snapshot = ReservationChange::query()->findOrFail($request->id);
            Reservation::query()->lockForUpdate()->findOrFail($snapshot->reservation_id);
            $payment = Payment::query()->lockForUpdate()->findOrFail(data_get($snapshot->metadata, 'payment_id'));
            $lockedRequest = ReservationChange::query()->lockForUpdate()->findOrFail($snapshot->id);
            if ($payment->origin !== PaymentOrigin::Provider || $lockedRequest->type !== 'refund_requested' || $lockedRequest->status !== 'requested') {
                throw new DomainException('Provider execution requires an open refund request for a provider-origin payment.');
            }
            $attempt = PaymentAttempt::query()->where('provider_payment_id', $payment->provider_reference)->lockForUpdate()->firstOrFail();
            $existing = ProviderRefund::query()->where('reservation_change_id', $lockedRequest->id)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }

            return ProviderRefund::query()->create([
                'payment_id' => $payment->id,
                'reservation_change_id' => $lockedRequest->id,
                'provider' => $attempt->provider,
                'environment' => $attempt->environment,
                'source_amount_minor' => $lockedRequest->amount_minor,
                'source_currency' => $payment->currency,
                'charge_amount_minor' => $payment->currency === $attempt->charge_currency
                    ? $lockedRequest->amount_minor
                    : BigDecimal::of($lockedRequest->amount_minor)->multipliedBy($attempt->charge_amount_minor)
                        ->dividedBy($attempt->source_amount_minor, 0, RoundingMode::HalfUp)->toInt(),
                'charge_currency' => $attempt->charge_currency,
                'idempotency_key' => (string) Str::uuid(),
                'provider_payment_id' => $payment->provider_reference,
                'state' => ProviderRefundState::Requested,
            ]);
        }, 3);
        if ($execution->state === ProviderRefundState::Succeeded) {
            return $execution;
        }

        $attempt = PaymentAttempt::query()->where('provider_payment_id', $execution->provider_payment_id)->firstOrFail();
        $execution->update(['state' => ProviderRefundState::Processing, 'attempt_count' => $execution->attempt_count + 1, 'last_attempted_at' => now()]);
        try {
            $remote = $this->gateways->for($attempt->integrationConnection)->refund(new ProviderRefundRequest(
                $execution->provider_payment_id,
                $execution->charge_amount_minor,
                $execution->charge_currency,
                $execution->idempotency_key,
            ));
            if (! in_array($remote->status, ['approved', 'success', 'succeeded'], true)
                || $remote->amountMinor !== $execution->charge_amount_minor) {
                $execution->update(['state' => ProviderRefundState::Mismatched, 'last_error' => 'Provider refund status or amount mismatch.']);

                return $execution->fresh();
            }
            $execution->update([
                'state' => ProviderRefundState::Succeeded,
                'provider_refund_id' => $remote->id,
                'response_checksum' => hash('sha256', json_encode(['id' => $remote->id, 'status' => $remote->status, 'amount_minor' => $remote->amountMinor], JSON_THROW_ON_ERROR)),
                'last_error' => null,
                'succeeded_at' => now(),
            ]);
            $this->completeRefund->handle($execution->reservationChange, $remote->id, $actorId);
        } catch (Throwable $exception) {
            $execution->update(['last_error' => Str::limit($exception->getMessage(), 500)]);
            throw $exception;
        }

        return $execution->fresh();
    }
}
