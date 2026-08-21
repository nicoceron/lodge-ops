<?php

namespace App\Services\Payments;

use App\Contracts\Payments\PaymentGatewayFactory;
use App\Data\Payments\ProviderPayment;
use App\Enums\PaymentAttemptState;
use App\Enums\ProviderEventState;
use App\Exceptions\CommercialWorkflowException as DomainException;
use App\Models\PaymentAttempt;
use App\Models\ProviderEvent;
use App\Services\DirectBooking\DirectBookingPaymentReconciler;
use App\Services\PaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class ProcessProviderEvent
{
    private const PROCESSING_LEASE_SECONDS = 90;

    public function __construct(
        private readonly PaymentGatewayFactory $gateways,
        private readonly PaymentService $payments,
        private readonly RecordProviderDispute $disputes,
        private readonly RecordSettlementRevision $settlements,
        private readonly DirectBookingPaymentReconciler $directBooking,
    ) {}

    public function handle(ProviderEvent $event): ProviderEvent
    {
        [$claimed, $shouldProcess] = DB::transaction(function () use ($event): array {
            $locked = ProviderEvent::query()->lockForUpdate()->findOrFail($event->id);
            if (in_array($locked->processing_state, [ProviderEventState::Processed, ProviderEventState::Duplicate, ProviderEventState::Mismatched], true)) {
                return [$locked, false];
            }
            $staleClaim = $locked->processing_state === ProviderEventState::Processing
                && $locked->updated_at->lte(now()->subSeconds(self::PROCESSING_LEASE_SECONDS));
            if ($locked->processing_state === ProviderEventState::Processing && ! $staleClaim) {
                return [$locked, false];
            }
            $locked->update([
                'processing_state' => ProviderEventState::Processing,
                'attempt_count' => $locked->attempt_count + 1,
                'last_error' => $staleClaim ? 'Reclaimed after the previous processing lease expired.' : null,
            ]);

            return [$locked, true];
        }, 3);
        if (! $shouldProcess) {
            return $claimed;
        }

        try {
            if ($this->isClaimEvent($claimed)) {
                return $this->mismatch($claimed, 'Mercado Pago claim notifications are unsupported in this slice and remain unapplied for Finance review.');
            }
            if ($this->isDisputeEvent($claimed)) {
                try {
                    $remote = $this->gateways->for($claimed->integrationConnection)->fetchDispute($claimed->resource_id);
                    $this->disputes->handle($claimed, $remote);
                    $claimed->update(['processing_state' => ProviderEventState::Processed, 'processed_at' => now(), 'last_error' => null]);

                    return $claimed->fresh();
                } catch (DomainException $exception) {
                    return $this->mismatch($claimed, $exception->getMessage());
                }
            }
            $providerPayment = $this->gateways->for($claimed->integrationConnection)->fetchPayment($claimed->resource_id);
            $attempt = PaymentAttempt::query()
                ->where('integration_connection_id', $claimed->integration_connection_id)
                ->where('provider', $claimed->provider)
                ->where('environment', $claimed->environment)
                ->where('provider_account', $claimed->provider_account)
                ->where(fn ($query) => $query->where('external_reference', $providerPayment->externalReference)->orWhere('provider_payment_id', $providerPayment->id))
                ->first();
            if ($attempt === null) {
                return $this->mismatch($claimed, 'No payment attempt matches the provider resource.');
            }
            if (! $this->matches($claimed, $attempt, $providerPayment)) {
                $attempt->update([
                    'state' => PaymentAttemptState::Mismatched,
                    'provider_payment_id' => $providerPayment->id,
                    'provider_status' => $providerPayment->status,
                    'provider_status_detail' => $providerPayment->statusDetail,
                    'last_error' => 'Provider identity, account, amount, or currency mismatch.',
                    'last_processed_at' => now(),
                ]);
                if ($providerPayment->status === 'approved') {
                    $this->directBooking->needsReview($attempt->fresh(), 'provider_identity_or_money_mismatch');
                }

                return $this->mismatch($claimed, 'Provider identity, account, amount, or currency mismatch.');
            }

            if ($providerPayment->status === 'refunded') {
                $attempt->update([
                    'provider_status' => $providerPayment->status,
                    'provider_status_detail' => $providerPayment->statusDetail,
                    'last_checked_at' => now(),
                    'last_processed_at' => now(),
                    'last_error' => 'Provider reports a refund; Finance must recover the identified provider refund before an Inn folio effect.',
                ]);
                $this->settlements->handle($attempt, $providerPayment);
                $claimed->update([
                    'processing_state' => ProviderEventState::Processed,
                    'processed_at' => now(),
                    'last_error' => 'Authoritative refund reported; awaiting provider-refund recovery identity.',
                ]);

                return $claimed->fresh();
            }
            if ($providerPayment->status === 'charged_back') {
                $attempt->update([
                    'provider_status' => $providerPayment->status,
                    'provider_status_detail' => $providerPayment->statusDetail,
                    'last_checked_at' => now(),
                    'last_processed_at' => now(),
                    'last_error' => 'Chargeback reported by payment lookup; awaiting/processing the authoritative chargeback topic before any folio impact.',
                ]);
                $this->settlements->handle($attempt, $providerPayment);
                $claimed->update([
                    'processing_state' => ProviderEventState::Processed,
                    'processed_at' => now(),
                    'last_error' => 'Chargeback payment status retained; authoritative chargeback lifecycle is handled by the chargeback topic.',
                ]);

                return $claimed->fresh();
            }
            $state = $this->state($providerPayment->status);
            if ($state === null) {
                $attempt->update([
                    'state' => PaymentAttemptState::Mismatched,
                    'provider_payment_id' => $providerPayment->id,
                    'provider_status' => $providerPayment->status,
                    'provider_status_detail' => $providerPayment->statusDetail,
                    'last_error' => 'Unknown provider payment state; left unapplied for Finance.',
                    'last_processed_at' => now(),
                ]);

                return $this->mismatch($claimed, 'Unknown provider payment state; left unapplied for Finance.');
            }
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
                    $this->directBooking->needsReview($attempt->fresh(), 'authoritative_payment_not_applicable');

                    return $this->mismatch($claimed, $exception->getMessage());
                }
                $this->settlements->handle($attempt, $providerPayment);
                $this->directBooking->approved($attempt->fresh());
            } elseif (in_array($state, [PaymentAttemptState::Rejected, PaymentAttemptState::Cancelled], true)) {
                $this->directBooking->failed($attempt->fresh(), 'provider_'.$state->value);
            }
            $claimed->update(['processing_state' => ProviderEventState::Processed, 'processed_at' => now(), 'last_error' => null]);

            return $claimed->fresh();
        } catch (Throwable $exception) {
            $claimed->update(['processing_state' => ProviderEventState::Failed, 'last_error' => Str::limit($exception->getMessage(), 500)]);
            throw $exception;
        }
    }

    private function matches(ProviderEvent $event, PaymentAttempt $attempt, ProviderPayment $payment): bool
    {
        return $event->resource_id === $payment->id
            && $event->provider_account === $payment->providerAccount
            && $attempt->external_reference === $payment->externalReference
            && $attempt->provider_account === $payment->providerAccount
            && $attempt->charge_amount_minor === $payment->amountMinor
            && $attempt->charge_currency === $payment->currency;
    }

    private function state(string $providerStatus): ?PaymentAttemptState
    {
        return match ($providerStatus) {
            'approved' => PaymentAttemptState::Approved,
            'pending', 'in_process', 'in_mediation' => PaymentAttemptState::Pending,
            'cancelled' => PaymentAttemptState::Cancelled,
            'rejected' => PaymentAttemptState::Rejected,
            default => null,
        };
    }

    private function mismatch(ProviderEvent $event, string $reason): ProviderEvent
    {
        $event->update(['processing_state' => ProviderEventState::Mismatched, 'processed_at' => now(), 'last_error' => $reason]);

        return $event->fresh();
    }

    private function isDisputeEvent(ProviderEvent $event): bool
    {
        $topic = strtolower((string) ($event->topic ?? $event->event_type ?? ''));

        return str_contains($topic, 'chargeback');
    }

    private function isClaimEvent(ProviderEvent $event): bool
    {
        $topic = strtolower((string) ($event->topic ?? $event->event_type ?? ''));

        return str_contains($topic, 'claim');
    }
}
