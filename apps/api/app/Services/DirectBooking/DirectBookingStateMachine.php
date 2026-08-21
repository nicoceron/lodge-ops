<?php

namespace App\Services\DirectBooking;

use App\Data\DirectBooking\DirectBookingTransitionResult;
use App\Enums\DirectBookingErrorCode;
use App\Enums\DirectBookingOrderState;
use App\Enums\DirectBookingTransitionAuthority;
use App\Enums\ReservationStatus;
use App\Exceptions\DirectBookingContractException;
use App\Models\DirectBookingOrder;
use App\Models\DirectBookingOrderEvent;
use App\Models\DirectBookingPropertySetting;
use App\Models\Reservation;
use App\Services\Documents\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
            if ($locked->pii_scrubbed_at !== null && $to !== $locked->state) {
                throw new DirectBookingContractException(DirectBookingErrorCode::Conflict, 'The retained booking session has already been closed.');
            }
            if (! $this->authorized($locked->state, $to, $authority)) {
                throw new DirectBookingContractException(DirectBookingErrorCode::Conflict, 'The requested booking transition is not authorized from the current state.');
            }

            $from = $locked->state;
            $nextVersion = $locked->state_version + 1;
            $changes = ['state' => $to, 'state_version' => $nextVersion];
            $changes += $this->timestampsFor($to);
            if ($to === DirectBookingOrderState::Quoted) {
                $quote = $locked->bookingQuote()->lockForUpdate()->first();
                if ($quote === null || $quote->property_id !== $locked->property_id || ! $quote->expires_at->isFuture()) {
                    throw new DirectBookingContractException(DirectBookingErrorCode::QuoteStale, 'The authoritative booking quote is missing or expired.');
                }
                $changes['quote_expires_at'] = $quote->expires_at;
            }
            if ($to === DirectBookingOrderState::Held) {
                $setting = DirectBookingPropertySetting::query()->where('property_id', $locked->property_id)->firstOrFail();
                $reservation = $this->lockedHeldReservation($locked);
                $maximumInitialExpiry = now()->addMinutes($setting->initial_hold_minutes);
                if ($reservation->hold_expires_at->greaterThan($maximumInitialExpiry)) {
                    throw new DirectBookingContractException(DirectBookingErrorCode::Conflict, 'The authoritative reservation hold exceeds direct-booking policy.');
                }
                $changes['hold_expires_at'] = $reservation->hold_expires_at;
            }
            if ($from === DirectBookingOrderState::PaymentPending && $to === DirectBookingOrderState::PaymentPending) {
                $setting = DirectBookingPropertySetting::query()->where('property_id', $locked->property_id)->firstOrFail();
                $reservation = $this->lockedHeldReservation($locked);
                if ($locked->hold_extended_at !== null || $locked->held_at === null || $locked->hold_expires_at === null
                    || ! $reservation->hold_expires_at->equalTo($locked->hold_expires_at)) {
                    throw new DirectBookingContractException(DirectBookingErrorCode::Conflict, 'The hosted-checkout hold extension is unavailable.');
                }
                $requested = (int) ($safeMetadata['hold_extension_minutes'] ?? 0);
                if ($requested < 1 || $requested > $setting->checkout_extension_minutes) {
                    throw new DirectBookingContractException(DirectBookingErrorCode::Validation, 'The hosted-checkout hold extension exceeds policy.', 422);
                }
                $absoluteExpiry = $locked->held_at->addMinutes($setting->maximum_hold_minutes);
                $requestedExpiry = $locked->hold_expires_at->addMinutes($requested);
                $nextExpiry = $requestedExpiry->lessThan($absoluteExpiry) ? $requestedExpiry : $absoluteExpiry;
                if (! $nextExpiry->isFuture() || $nextExpiry->lessThanOrEqualTo($locked->hold_expires_at)) {
                    throw new DirectBookingContractException(DirectBookingErrorCode::HoldExpired, 'The reservation hold can no longer be extended.');
                }
                $reservation->forceFill(['hold_expires_at' => $nextExpiry])->save();
                if ($locked->payment_request_id !== null) {
                    $request = $locked->paymentRequest()->lockForUpdate()->firstOrFail();
                    $request->forceFill(['expires_at' => $nextExpiry])->save();
                }
                $changes['hold_expires_at'] = $nextExpiry;
                $changes['checkout_expires_at'] = $nextExpiry;
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
                'event_type' => 'transition',
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

    public function recordPiiScrubbed(DirectBookingOrder $order, string $retryIdentity): DirectBookingTransitionResult
    {
        if (! preg_match('/^[A-Za-z0-9._:-]{16,160}$/', $retryIdentity)) {
            throw new DirectBookingContractException(DirectBookingErrorCode::Validation, 'The retry identity is invalid.', 422);
        }
        $requestChecksum = app(CanonicalJson::class)->checksum(['event_type' => 'pii_scrubbed']);

        return DB::transaction(function () use ($order, $retryIdentity, $requestChecksum): DirectBookingTransitionResult {
            $locked = DirectBookingOrder::query()->lockForUpdate()->findOrFail($order->id);
            $existing = DirectBookingOrderEvent::query()
                ->where('direct_booking_order_id', $locked->id)
                ->where('retry_identity', $retryIdentity)
                ->first();
            if ($existing !== null) {
                if ($existing->event_type !== 'pii_scrubbed' || ! hash_equals($existing->request_checksum, $requestChecksum)) {
                    throw new DirectBookingContractException(DirectBookingErrorCode::IdempotencyConflict, 'The maintenance retry identity conflicts.');
                }

                return new DirectBookingTransitionResult($locked, $existing, true);
            }
            if (! in_array($locked->state, [
                DirectBookingOrderState::Expired,
                DirectBookingOrderState::Confirmed,
                DirectBookingOrderState::Canceled,
                DirectBookingOrderState::Refunded,
            ], true) || $locked->retained_until->isFuture() || $locked->pii_scrubbed_at !== null) {
                throw new DirectBookingContractException(DirectBookingErrorCode::Conflict, 'The booking order is not eligible for PII retention cleanup.');
            }

            $nextVersion = $locked->state_version + 1;
            $locked->forceFill([
                'state_version' => $nextVersion,
                'token_hash' => hash('sha256', 'purged:'.Str::random(64)),
                'recovery_token_hash' => null,
                'guest_contact_encrypted' => null,
                'guest_contact_checksum' => null,
                'attribution' => null,
                'ip_prefix_hash' => null,
                'revoked_at' => $locked->revoked_at ?? now(),
                'pii_scrubbed_at' => now(),
            ])->save();
            $event = DirectBookingOrderEvent::query()->create([
                'direct_booking_order_id' => $locked->id,
                'event_type' => 'pii_scrubbed',
                'sequence' => $nextVersion - 1,
                'from_state' => $locked->state,
                'to_state' => $locked->state,
                'authority' => DirectBookingTransitionAuthority::Scheduler,
                'retry_identity' => $retryIdentity,
                'request_checksum' => $requestChecksum,
                'state_version' => $nextVersion,
                'safe_metadata' => ['scheduler_outcome' => 'session_pii_scrubbed'],
                'occurred_at' => now(),
            ]);

            return new DirectBookingTransitionResult($locked->fresh(), $event, false);
        }, 3);
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
                [DirectBookingOrderState::FinanceReview, $scheduler],
                [DirectBookingOrderState::EvidenceRejected, DirectBookingTransitionAuthority::EvidenceScanner],
            ],
            DirectBookingOrderState::FinanceReview->value => [
                [DirectBookingOrderState::Confirmed, DirectBookingTransitionAuthority::Finance],
                [DirectBookingOrderState::EvidenceRejected, DirectBookingTransitionAuthority::Finance],
                [DirectBookingOrderState::Refunded, DirectBookingTransitionAuthority::Refund],
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

    private function lockedHeldReservation(DirectBookingOrder $order): Reservation
    {
        $reservation = $order->reservation()->lockForUpdate()->first();
        if ($reservation === null || $reservation->property_id !== $order->property_id
            || $reservation->booking_quote_id !== $order->booking_quote_id
            || $reservation->status !== ReservationStatus::Hold
            || $reservation->hold_expires_at?->isFuture() !== true) {
            throw new DirectBookingContractException(DirectBookingErrorCode::HoldExpired, 'The authoritative reservation hold is missing or expired.');
        }

        return $reservation;
    }
}
