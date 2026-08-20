<?php

namespace App\Services\DirectBooking;

use App\Data\DirectBooking\DirectBookingTransitionResult;
use App\Enums\DirectBookingErrorCode;
use App\Enums\DirectBookingOrderState;
use App\Enums\DirectBookingTransitionAuthority;
use App\Exceptions\DirectBookingContractException;
use App\Models\DirectBookingOrder;
use App\Models\DirectBookingOrderEvent;
use App\Models\DirectBookingPropertySetting;
use App\Services\Documents\CanonicalJson;
use Illuminate\Support\Facades\DB;

final class DirectBookingStateMachine
{
    /** @param array<string, mixed> $safeMetadata */
    public function transition(
        DirectBookingOrder $order,
        DirectBookingOrderState $to,
        DirectBookingTransitionAuthority $authority,
        int $expectedVersion,
        string $retryIdentity,
        array $safeMetadata = [],
    ): DirectBookingTransitionResult {
        if (! preg_match('/^[A-Za-z0-9._:-]{16,160}$/', $retryIdentity)) {
            throw new DirectBookingContractException(DirectBookingErrorCode::Validation, 'The retry identity is invalid.', 422);
        }
        $safeMetadata = app(DirectBookingPrivacy::class)->safeEventMetadata($safeMetadata);
        $requestChecksum = app(CanonicalJson::class)->checksum([
            'to' => $to,
            'authority' => $authority,
            'expected_version' => $expectedVersion,
            'metadata' => $safeMetadata,
        ]);

        return DB::transaction(function () use ($order, $to, $authority, $expectedVersion, $retryIdentity, $safeMetadata, $requestChecksum): DirectBookingTransitionResult {
            $locked = DirectBookingOrder::query()->lockForUpdate()->findOrFail($order->id);
            $existing = DirectBookingOrderEvent::query()
                ->where('direct_booking_order_id', $locked->id)
                ->where('retry_identity', $retryIdentity)
                ->first();
            if ($existing !== null) {
                if (! hash_equals($existing->request_checksum, $requestChecksum)) {
                    throw new DirectBookingContractException(
                        DirectBookingErrorCode::IdempotencyConflict,
                        'This retry identity was already used for different transition facts.',
                    );
                }

                return new DirectBookingTransitionResult($locked, $existing, true);
            }
            if ($locked->state_version !== $expectedVersion) {
                throw new DirectBookingContractException(DirectBookingErrorCode::Conflict, 'The booking state version changed. Refresh status before retrying.');
            }
            if (! $this->authorized($locked->state, $to, $authority)) {
                throw new DirectBookingContractException(DirectBookingErrorCode::Conflict, 'The requested booking transition is not authorized from the current state.');
            }

            $from = $locked->state;
            $nextVersion = $locked->state_version + 1;
            $changes = ['state' => $to, 'state_version' => $nextVersion];
            $changes += $this->timestampsFor($to);
            if ($to === DirectBookingOrderState::Held) {
                $setting = DirectBookingPropertySetting::query()->where('property_id', $locked->property_id)->firstOrFail();
                $changes['expires_at'] = now()->addMinutes($setting->initial_hold_minutes);
            }
            if ($from === DirectBookingOrderState::PaymentPending && $to === DirectBookingOrderState::PaymentPending) {
                $setting = DirectBookingPropertySetting::query()->where('property_id', $locked->property_id)->firstOrFail();
                if ($locked->hold_extended_at !== null || $locked->held_at === null) {
                    throw new DirectBookingContractException(DirectBookingErrorCode::Conflict, 'The hosted-checkout hold extension is unavailable.');
                }
                $requested = (int) ($safeMetadata['hold_extension_minutes'] ?? 0);
                if ($requested < 1 || $requested > $setting->checkout_extension_minutes) {
                    throw new DirectBookingContractException(DirectBookingErrorCode::Validation, 'The hosted-checkout hold extension exceeds policy.', 422);
                }
                $absoluteExpiry = $locked->held_at->addMinutes($setting->maximum_hold_minutes);
                $requestedExpiry = $locked->expires_at->addMinutes($requested);
                $changes['expires_at'] = $requestedExpiry->lessThan($absoluteExpiry) ? $requestedExpiry : $absoluteExpiry;
                $changes['hold_extended_at'] = now();
            }
            if ($to === DirectBookingOrderState::PaymentFailed) {
                $changes['safe_failure_code'] = DirectBookingErrorCode::PaymentFailed;
            } elseif ($to === DirectBookingOrderState::PaidNeedsReview) {
                $changes['safe_failure_code'] = DirectBookingErrorCode::PaidNeedsReview;
            } elseif (in_array($to, [DirectBookingOrderState::Quoted, DirectBookingOrderState::Held, DirectBookingOrderState::PaymentPending], true)) {
                $changes['safe_failure_code'] = null;
            }
            $locked->forceFill($changes)->save();
            $event = DirectBookingOrderEvent::query()->create([
                'direct_booking_order_id' => $locked->id,
                'sequence' => $nextVersion - 1,
                'from_state' => $from,
                'to_state' => $to,
                'authority' => $authority,
                'retry_identity' => $retryIdentity,
                'request_checksum' => $requestChecksum,
                'state_version' => $nextVersion,
                'safe_metadata' => $safeMetadata ?: null,
                'occurred_at' => now(),
            ]);

            return new DirectBookingTransitionResult($locked->fresh(), $event, false);
        }, 3);
    }

    public function authorized(DirectBookingOrderState $from, DirectBookingOrderState $to, DirectBookingTransitionAuthority $authority): bool
    {
        return in_array([$to, $authority], $this->transitions()[$from->value] ?? [], true);
    }

    /** @return array<string, list<array{DirectBookingOrderState, DirectBookingTransitionAuthority}>> */
    public function transitions(): array
    {
        $pricing = DirectBookingTransitionAuthority::Pricing;
        $inventory = DirectBookingTransitionAuthority::Inventory;
        $payments = DirectBookingTransitionAuthority::PaymentOrchestrator;
        $provider = DirectBookingTransitionAuthority::ProviderLookup;
        $reservation = DirectBookingTransitionAuthority::Reservation;
        $scheduler = DirectBookingTransitionAuthority::Scheduler;

        return [
            DirectBookingOrderState::Started->value => [[DirectBookingOrderState::Quoted, $pricing], [DirectBookingOrderState::Expired, $scheduler]],
            DirectBookingOrderState::Quoted->value => [[DirectBookingOrderState::Held, $inventory], [DirectBookingOrderState::Expired, $scheduler]],
            DirectBookingOrderState::Held->value => [
                [DirectBookingOrderState::PaymentPending, $payments],
                [DirectBookingOrderState::AwaitingManualPayment, $payments],
                [DirectBookingOrderState::Expired, $scheduler],
            ],
            DirectBookingOrderState::PaymentPending->value => [
                [DirectBookingOrderState::PaymentPending, $payments],
                [DirectBookingOrderState::PaidPendingConfirmation, $provider],
                [DirectBookingOrderState::PaymentFailed, $provider],
                [DirectBookingOrderState::PaidNeedsReview, $provider],
                [DirectBookingOrderState::Expired, $scheduler],
            ],
            DirectBookingOrderState::AwaitingManualPayment->value => [
                [DirectBookingOrderState::EvidencePending, DirectBookingTransitionAuthority::GuestEvidence],
                [DirectBookingOrderState::Expired, $scheduler],
            ],
            DirectBookingOrderState::EvidencePending->value => [
                [DirectBookingOrderState::FinanceReview, DirectBookingTransitionAuthority::EvidenceScanner],
                [DirectBookingOrderState::EvidenceRejected, DirectBookingTransitionAuthority::EvidenceScanner],
                [DirectBookingOrderState::Expired, $scheduler],
            ],
            DirectBookingOrderState::FinanceReview->value => [
                [DirectBookingOrderState::Confirmed, DirectBookingTransitionAuthority::Finance],
                [DirectBookingOrderState::EvidenceRejected, DirectBookingTransitionAuthority::Finance],
                [DirectBookingOrderState::Expired, $scheduler],
            ],
            DirectBookingOrderState::PaidPendingConfirmation->value => [
                [DirectBookingOrderState::Confirmed, $reservation],
                [DirectBookingOrderState::PaidNeedsReview, $reservation],
            ],
            DirectBookingOrderState::PaymentFailed->value => [[DirectBookingOrderState::PaymentPending, $payments], [DirectBookingOrderState::Expired, $scheduler]],
            DirectBookingOrderState::EvidenceRejected->value => [[DirectBookingOrderState::AwaitingManualPayment, $payments], [DirectBookingOrderState::Expired, $scheduler]],
            DirectBookingOrderState::Expired->value => [
                [DirectBookingOrderState::Started, DirectBookingTransitionAuthority::Recovery],
                [DirectBookingOrderState::PaidNeedsReview, $provider],
                [DirectBookingOrderState::Expired, $scheduler],
            ],
            DirectBookingOrderState::PaidNeedsReview->value => [
                [DirectBookingOrderState::Confirmed, DirectBookingTransitionAuthority::Finance],
                [DirectBookingOrderState::Refunded, DirectBookingTransitionAuthority::Refund],
            ],
            DirectBookingOrderState::Confirmed->value => [
                [DirectBookingOrderState::Canceled, DirectBookingTransitionAuthority::Cancellation],
                [DirectBookingOrderState::Refunded, DirectBookingTransitionAuthority::Refund],
                [DirectBookingOrderState::Confirmed, $scheduler],
            ],
            DirectBookingOrderState::Canceled->value => [
                [DirectBookingOrderState::Refunded, DirectBookingTransitionAuthority::Refund],
                [DirectBookingOrderState::Canceled, $scheduler],
            ],
            DirectBookingOrderState::Refunded->value => [[DirectBookingOrderState::Refunded, $scheduler]],
        ];
    }

    /** @return array<string, mixed> */
    private function timestampsFor(DirectBookingOrderState $to): array
    {
        return match ($to) {
            DirectBookingOrderState::Quoted => ['quoted_at' => now()],
            DirectBookingOrderState::Held => ['held_at' => now()],
            DirectBookingOrderState::PaymentPending, DirectBookingOrderState::AwaitingManualPayment => ['payment_started_at' => now()],
            DirectBookingOrderState::PaidPendingConfirmation, DirectBookingOrderState::PaidNeedsReview => ['paid_at' => now()],
            DirectBookingOrderState::Confirmed => ['confirmed_at' => now()],
            default => [],
        };
    }
}
