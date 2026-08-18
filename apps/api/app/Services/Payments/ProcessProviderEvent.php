<?php

namespace App\Services\Payments;

use App\Contracts\Payments\PaymentGatewayFactory;
use App\Data\Payments\ProviderPayment;
use App\Enums\PaymentAttemptState;
use App\Enums\ProviderEventState;
use App\Exceptions\CommercialWorkflowException as DomainException;
use App\Models\PaymentAttempt;
use App\Models\ProviderEvent;
use App\Models\SettlementEntry;
use App\Services\PaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class ProcessProviderEvent
{
    public function __construct(private readonly PaymentGatewayFactory $gateways, private readonly PaymentService $payments) {}

    public function handle(ProviderEvent $event): ProviderEvent
    {
        $claimed = DB::transaction(function () use ($event): ProviderEvent {
            $locked = ProviderEvent::query()->lockForUpdate()->findOrFail($event->id);
            if (in_array($locked->processing_state, [ProviderEventState::Processed, ProviderEventState::Duplicate], true)) {
                return $locked;
            }
            $locked->update(['processing_state' => ProviderEventState::Processing, 'attempt_count' => $locked->attempt_count + 1]);

            return $locked;
        }, 3);
        if ($claimed->processing_state !== ProviderEventState::Processing) {
            return $claimed;
        }

        try {
            $providerPayment = $this->gateways->for($claimed->integrationConnection)->fetchPayment($claimed->resource_id);
            $attempt = PaymentAttempt::query()
                ->where('provider', $claimed->provider)
                ->where('environment', $claimed->environment)
                ->where(fn ($query) => $query->where('external_reference', $providerPayment->externalReference)->orWhere('provider_payment_id', $providerPayment->id))
                ->first();
            if ($attempt === null) {
                return $this->mismatch($claimed, 'No payment attempt matches the provider resource.');
            }
            if (! $this->matches($attempt, $providerPayment)) {
                $attempt->update([
                    'state' => PaymentAttemptState::Mismatched,
                    'provider_payment_id' => $providerPayment->id,
                    'provider_status' => $providerPayment->status,
                    'provider_status_detail' => $providerPayment->statusDetail,
                    'last_error' => 'Provider identity, account, amount, or currency mismatch.',
                    'last_processed_at' => now(),
                ]);

                return $this->mismatch($claimed, 'Provider identity, account, amount, or currency mismatch.');
            }

            $state = $this->state($providerPayment->status);
            $attempt->update([
                'state' => $state,
                'provider_payment_id' => $providerPayment->id,
                'provider_status' => $providerPayment->status,
                'provider_status_detail' => $providerPayment->statusDetail,
                'last_checked_at' => now(),
                'last_processed_at' => now(),
                'last_error' => null,
            ]);
            if ($state === PaymentAttemptState::Approved) {
                try {
                    $this->payments->recordProvider($attempt->fresh(), $providerPayment);
                } catch (DomainException $exception) {
                    $attempt->update(['state' => PaymentAttemptState::Mismatched, 'last_error' => $exception->getMessage()]);

                    return $this->mismatch($claimed, $exception->getMessage());
                }
                $this->recordSettlement($attempt, $providerPayment);
            }
            $claimed->update(['processing_state' => ProviderEventState::Processed, 'processed_at' => now(), 'last_error' => null]);

            return $claimed->fresh();
        } catch (Throwable $exception) {
            $claimed->update(['processing_state' => ProviderEventState::Failed, 'last_error' => Str::limit($exception->getMessage(), 500)]);
            throw $exception;
        }
    }

    private function matches(PaymentAttempt $attempt, ProviderPayment $payment): bool
    {
        return $attempt->external_reference === $payment->externalReference
            && $attempt->provider_account === $payment->providerAccount
            && $attempt->charge_amount_minor === $payment->amountMinor
            && $attempt->charge_currency === $payment->currency;
    }

    private function state(string $providerStatus): PaymentAttemptState
    {
        return match ($providerStatus) {
            'approved' => PaymentAttemptState::Approved,
            'pending', 'in_process', 'in_mediation' => PaymentAttemptState::Pending,
            'cancelled' => PaymentAttemptState::Cancelled,
            'refunded', 'charged_back' => PaymentAttemptState::Mismatched,
            default => PaymentAttemptState::Rejected,
        };
    }

    private function mismatch(ProviderEvent $event, string $reason): ProviderEvent
    {
        $event->update(['processing_state' => ProviderEventState::Mismatched, 'processed_at' => now(), 'last_error' => $reason]);

        return $event->fresh();
    }

    private function recordSettlement(PaymentAttempt $attempt, ProviderPayment $payment): void
    {
        $gross = $payment->settlement['gross_minor'] ?? $payment->amountMinor;
        $fee = $payment->settlement['fee_minor'] ?? 0;
        $net = $payment->settlement['net_minor'] ?? ($gross - $fee);
        SettlementEntry::query()->updateOrCreate([
            'provider' => $attempt->provider,
            'provider_account' => $attempt->provider_account,
            'source_type' => 'payment',
            'source_id' => $payment->id,
        ], [
            'integration_connection_id' => $attempt->integration_connection_id,
            'gross_minor' => $gross,
            'fee_minor' => $fee,
            'net_minor' => $net,
            'currency' => $payment->currency,
            'source_checksum' => hash('sha256', json_encode($payment->settlement, JSON_THROW_ON_ERROR)),
            'reconciliation_state' => $gross - $fee === $net ? 'matched' : 'variance',
        ]);
    }
}
