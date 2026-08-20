<?php

namespace App\Services\Payments;

use App\Data\Payments\ProviderDispute as ProviderDisputeData;
use App\Enums\FolioLineType;
use App\Enums\FolioStatus;
use App\Enums\ProviderDisputeImpactState;
use App\Enums\ProviderDisputeState;
use App\Exceptions\CommercialWorkflowException as DomainException;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\ProviderDispute;
use App\Models\ProviderDisputeRevision;
use App\Models\ProviderEvent;
use App\Models\Reservation;
use App\Services\FolioService;
use Illuminate\Support\Facades\DB;

final class RecordProviderDispute
{
    public function __construct(private readonly FolioService $folio) {}

    public function handle(ProviderEvent $event, ProviderDisputeData $remote): ProviderDispute
    {
        return DB::transaction(function () use ($event, $remote): ProviderDispute {
            if ($event->resource_id !== $remote->id) {
                throw new DomainException('The authoritative chargeback resource identity does not match the provider event.');
            }
            $attempt = PaymentAttempt::query()
                ->where('integration_connection_id', $event->integration_connection_id)
                ->where('provider', $event->provider)
                ->where('environment', $event->environment)
                ->where('provider_account', $event->provider_account)
                ->where('provider_payment_id', $remote->providerPaymentId)
                ->first();
            if ($attempt === null || $remote->providerAccount === '' || $attempt->provider_account !== $remote->providerAccount) {
                throw new DomainException('The authoritative chargeback account or payment identity is unknown.');
            }
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($attempt->reservation_id);
            $payment = Payment::query()->lockForUpdate()
                ->where('provider', $attempt->provider)
                ->where('environment', $attempt->environment)
                ->where('provider_account', $attempt->provider_account)
                ->where('provider_reference', $remote->providerPaymentId)
                ->firstOrFail();
            $attempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if ($attempt->reservation_id !== $reservation->id
                || $attempt->provider_account !== $remote->providerAccount
                || $attempt->provider_payment_id !== $remote->providerPaymentId) {
                throw new DomainException('The authoritative chargeback account or payment identity changed while it was being reconciled.');
            }
            if ($remote->currency !== $payment->currency || $remote->amountMinor <= 0 || $remote->amountMinor > $payment->amount_minor) {
                throw new DomainException('The authoritative chargeback amount or currency does not match the Inn payment.');
            }
            $state = $this->state($remote);
            $dispute = ProviderDispute::query()->firstOrCreate([
                'provider' => $attempt->provider,
                'environment' => $attempt->environment,
                'provider_account' => $attempt->provider_account,
                'provider_dispute_id' => $remote->id,
            ], [
                'property_id' => $attempt->property_id,
                'reservation_id' => $reservation->id,
                'payment_id' => $payment->id,
                'payment_attempt_id' => $attempt->id,
                'integration_connection_id' => $attempt->integration_connection_id,
                'provider_payment_id' => $remote->providerPaymentId,
                'state' => $state,
                'impact_state' => ProviderDisputeImpactState::None,
                'amount_minor' => $remote->amountMinor,
                'currency' => $remote->currency,
                'last_checked_at' => now(),
            ]);
            $locked = ProviderDispute::query()->lockForUpdate()->findOrFail($dispute->id);
            $facts = [
                'provider_dispute_id' => $remote->id,
                'provider_payment_id' => $remote->providerPaymentId,
                'provider_account' => $remote->providerAccount,
                'status' => $remote->status,
                'status_detail' => $remote->statusDetail,
                'amount_minor' => $remote->amountMinor,
                'currency' => $remote->currency,
                'reason' => $remote->reason,
                'coverage_applied' => $remote->coverageApplied,
                'documentation_required' => $remote->documentationRequired,
                'documentation_deadline' => $remote->documentationDeadline?->toIso8601String(),
                'provider_created_at' => $remote->providerCreatedAt?->toIso8601String(),
                'provider_updated_at' => $remote->providerUpdatedAt?->toIso8601String(),
            ];
            $checksum = hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR));
            if (! $locked->revisions()->where('facts_checksum', $checksum)->exists()) {
                $revision = $locked->current_revision + 1;
                ProviderDisputeRevision::query()->create([
                    'provider_dispute_id' => $locked->id,
                    'provider_event_id' => $event->id,
                    'revision' => $revision,
                    'status' => $remote->status,
                    'status_detail' => $remote->statusDetail,
                    'amount_minor' => $remote->amountMinor,
                    'currency' => $remote->currency,
                    'reason' => $remote->reason,
                    'coverage_applied' => $remote->coverageApplied,
                    'documentation_required' => $remote->documentationRequired,
                    'documentation_deadline' => $remote->documentationDeadline,
                    'provider_created_at' => $remote->providerCreatedAt,
                    'provider_updated_at' => $remote->providerUpdatedAt,
                    'provider_facts' => $facts,
                    'facts_checksum' => $checksum,
                    'recorded_at' => now(),
                ]);
                $locked->current_revision = $revision;
            }
            $locked->fill([
                'state' => $state,
                'status_detail' => $remote->statusDetail,
                'amount_minor' => $remote->amountMinor,
                'currency' => $remote->currency,
                'reason' => $remote->reason,
                'coverage_applied' => $remote->coverageApplied,
                'documentation_required' => $remote->documentationRequired,
                'documentation_deadline' => $remote->documentationDeadline,
                'provider_created_at' => $remote->providerCreatedAt,
                'provider_updated_at' => $remote->providerUpdatedAt,
                'last_checked_at' => now(),
            ])->save();
            $this->applyImpact($locked, $reservation, $payment);

            return $locked->fresh('revisions');
        }, 3);
    }

    private function applyImpact(ProviderDispute $dispute, Reservation $reservation, Payment $payment): void
    {
        $chargebackLine = FolioLine::query()
            ->where('reservation_id', $reservation->id)
            ->where('metadata->provider_dispute_id', $dispute->id)
            ->where('metadata->provider_dispute_effect', 'chargeback')
            ->orderBy('posted_at')
            ->first();
        if ($dispute->state === ProviderDisputeState::Lost && $chargebackLine === null) {
            if ($reservation->folio_status === FolioStatus::Closed) {
                $dispute->update(['impact_state' => ProviderDisputeImpactState::PendingFinance]);

                return;
            }
            $refunded = (int) $reservation->changes()->where('type', 'refund_completed')->where('status', 'completed')
                ->where('metadata->payment_id', $payment->id)->sum('amount_minor');
            $appliedChargebacks = FolioLine::query()
                ->where('payment_id', $payment->id)
                ->where('metadata->provider_dispute_effect', 'chargeback')
                ->get()
                ->reject(fn (FolioLine $line): bool => $line->reversal !== null)
                ->sum('net_amount_minor');
            $amount = min($dispute->amount_minor, max(0, $payment->amount_minor - $refunded - $appliedChargebacks));
            if ($amount === 0) {
                $dispute->update(['impact_state' => ProviderDisputeImpactState::None]);

                return;
            }
            $this->folio->postProviderAdjustment(
                $payment,
                FolioLineType::Refund,
                'Provider chargeback settled against the property',
                $amount,
                ['provider_dispute_id' => $dispute->id, 'provider_dispute_effect' => 'chargeback'],
            );
            $dispute->update(['impact_state' => ProviderDisputeImpactState::Applied]);
        } elseif ($dispute->state === ProviderDisputeState::Won && $chargebackLine !== null && $chargebackLine->reversal === null) {
            if ($reservation->folio_status === FolioStatus::Closed) {
                $dispute->update(['impact_state' => ProviderDisputeImpactState::PendingFinance]);

                return;
            }
            $this->folio->reverse($chargebackLine, 'Provider chargeback resolved in favor of the property.', null);
            $dispute->update(['impact_state' => ProviderDisputeImpactState::Reversed]);
        } elseif ($dispute->state === ProviderDisputeState::UnderReview) {
            $dispute->update(['impact_state' => ProviderDisputeImpactState::PendingFinance]);
        }
    }

    private function state(ProviderDisputeData $remote): ProviderDisputeState
    {
        return match ($remote->statusDetail) {
            'settled' => ProviderDisputeState::Lost,
            'reimbursed' => ProviderDisputeState::Won,
            'in_process' => ProviderDisputeState::UnderReview,
            default => $remote->coverageApplied === true ? ProviderDisputeState::Won
                : ($remote->coverageApplied === false ? ProviderDisputeState::Lost : ProviderDisputeState::Open),
        };
    }
}
