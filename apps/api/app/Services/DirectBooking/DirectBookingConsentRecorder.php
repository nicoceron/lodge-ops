<?php

namespace App\Services\DirectBooking;

use App\Enums\DirectBookingErrorCode;
use App\Enums\DirectBookingPublicationKind;
use App\Enums\DirectBookingPublicationState;
use App\Exceptions\DirectBookingContractException;
use App\Models\DirectBookingOrder;
use App\Models\DirectBookingOrderConsent;
use App\Models\DirectBookingPublication;
use App\Services\Documents\CanonicalJson;
use Illuminate\Support\Facades\DB;

final class DirectBookingConsentRecorder
{
    /**
     * @param  array<string, array{publication_id: string, accepted: bool}>  $decisions
     */
    public function record(DirectBookingOrder $order, array $decisions, ?string $remoteIp): DirectBookingOrder
    {
        return DB::transaction(function () use ($order, $decisions, $remoteIp): DirectBookingOrder {
            $locked = DirectBookingOrder::query()->lockForUpdate()->findOrFail($order->id);
            $required = [
                DirectBookingPublicationKind::Terms,
                DirectBookingPublicationKind::Privacy,
                DirectBookingPublicationKind::Cancellation,
                DirectBookingPublicationKind::NoShow,
            ];
            $snapshots = [];
            foreach ($decisions as $kindValue => $decision) {
                $kind = DirectBookingPublicationKind::tryFrom($kindValue);
                if ($kind === null || ! in_array($kind, [...$required, DirectBookingPublicationKind::MarketingConsent], true)) {
                    throw new DirectBookingContractException(DirectBookingErrorCode::Validation, 'A consent decision uses an unsupported policy kind.', 422);
                }
                $publication = DirectBookingPublication::query()
                    ->whereKey($decision['publication_id'])
                    ->where('property_id', $locked->property_id)
                    ->where('locale', $locked->locale)
                    ->where('kind', $kind)
                    ->where('state', DirectBookingPublicationState::Published)
                    ->where(fn ($query) => $query->whereNull('effective_at')->orWhere('effective_at', '<=', now()))
                    ->first();
                if ($publication === null) {
                    throw new DirectBookingContractException(DirectBookingErrorCode::Validation, 'A required policy version is unavailable.', 422);
                }
                if (in_array($kind, $required, true) && ! $decision['accepted']) {
                    throw new DirectBookingContractException(DirectBookingErrorCode::Validation, 'Required booking policies must be accepted.', 422);
                }
                $existing = DirectBookingOrderConsent::query()
                    ->where('direct_booking_order_id', $locked->id)->where('kind', $kind)->first();
                $snapshot = [
                    'kind' => $kind->value,
                    'publication_id' => $publication->id,
                    'publication_version' => $publication->version,
                    'publication_checksum' => $publication->checksum,
                    'accepted' => (bool) $decision['accepted'],
                ];
                if ($existing !== null) {
                    $existingSnapshot = [
                        'kind' => $existing->kind->value,
                        'publication_id' => $existing->publication_id,
                        'publication_version' => $existing->publication_version,
                        'publication_checksum' => $existing->publication_checksum,
                        'accepted' => $existing->accepted,
                    ];
                    if ($existingSnapshot !== $snapshot) {
                        throw new DirectBookingContractException(DirectBookingErrorCode::Conflict, 'A consent kind already has a different immutable decision.');
                    }
                } else {
                    DirectBookingOrderConsent::query()->create($snapshot + [
                        'direct_booking_order_id' => $locked->id,
                        'ip_prefix_hash' => app(DirectBookingPrivacy::class)->ipPrefixHash($remoteIp),
                        'recorded_at' => now(),
                    ]);
                }
                $snapshots[] = $snapshot;
            }
            foreach ($required as $kind) {
                if (! collect($snapshots)->contains('kind', $kind->value)) {
                    throw new DirectBookingContractException(DirectBookingErrorCode::Validation, 'All required policy decisions must be submitted together.', 422);
                }
            }
            $locked->forceFill([
                'consent_checksum' => app(CanonicalJson::class)->checksum(collect($snapshots)->sortBy('kind')->values()->all()),
            ])->save();

            return $locked->fresh('consents');
        });
    }
}
