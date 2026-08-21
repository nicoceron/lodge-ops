<?php

namespace App\Console\Commands;

use App\Enums\DirectBookingOrderState;
use App\Enums\DirectBookingTransitionAuthority;
use App\Enums\ReservationStatus;
use App\Models\DirectBookingCommandResponse;
use App\Models\DirectBookingOrder;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Services\DirectBooking\DirectBookingStateMachine;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MaintainDirectBookingOrders extends Command
{
    protected $signature = 'direct-booking:maintain {--tenant=} {--batch=100} {--cleanup}';

    protected $description = 'Expire direct-booking sessions and scrub retained session PII with versioned outcomes.';

    public function handle(DirectBookingStateMachine $states): int
    {
        $expired = 0;
        $scrubbed = 0;
        $responses = 0;
        Tenant::query()->when($this->option('tenant'), fn ($query, $id) => $query->whereKey($id))->get()
            ->each(function (Tenant $tenant) use ($states, &$expired, &$scrubbed, &$responses): void {
                app(TenantContext::class)->set($tenant);
                if ($this->option('cleanup')) {
                    $scrubbed += $this->scrub($states);
                    $responses += DirectBookingCommandResponse::query()
                        ->where('expires_at', '<=', now())
                        ->limit(max(1, (int) $this->option('batch')))
                        ->delete();
                } else {
                    $expired += $this->expire($states);
                }
            });
        app(TenantContext::class)->clear();
        $this->info("Expired {$expired}; scrubbed {$scrubbed} direct-booking order(s); deleted {$responses} expired command response(s).");

        return self::SUCCESS;
    }

    private function expire(DirectBookingStateMachine $states): int
    {
        $count = 0;
        DirectBookingOrder::query()
            ->where(function ($query): void {
                $query->where(fn ($candidate) => $candidate
                    ->where('state', DirectBookingOrderState::Started)
                    ->where('session_expires_at', '<=', now()))
                    ->orWhere(fn ($candidate) => $candidate
                        ->where('state', DirectBookingOrderState::Quoted)
                        ->where('quote_expires_at', '<=', now()))
                    ->orWhere(fn ($candidate) => $candidate
                        ->whereIn('state', [
                            DirectBookingOrderState::Held,
                            DirectBookingOrderState::PaymentPending,
                            DirectBookingOrderState::AwaitingManualPayment,
                            DirectBookingOrderState::PaymentFailed,
                            DirectBookingOrderState::EvidenceRejected,
                        ])->where('hold_expires_at', '<=', now()))
                    ->orWhere(fn ($candidate) => $candidate
                        ->where('state', DirectBookingOrderState::EvidencePending)
                        ->where('hold_expires_at', '<=', now()));
            })->orderBy('id')->limit((int) $this->option('batch'))->get()
            ->each(function (DirectBookingOrder $order) use ($states, &$count): void {
                $lateManualEvidence = $order->state === DirectBookingOrderState::EvidencePending;
                $states->transition(
                    $order,
                    $lateManualEvidence ? DirectBookingOrderState::FinanceReview : DirectBookingOrderState::Expired,
                    DirectBookingTransitionAuthority::Scheduler,
                    $order->state_version,
                    'expire:'.$order->public_reference.':'.$order->state_version,
                    ['scheduler_outcome' => $lateManualEvidence ? 'late_manual_evidence_review' : 'lifecycle_expired'],
                );
                $count++;
            });

        return $count;
    }

    private function scrub(DirectBookingStateMachine $states): int
    {
        $count = 0;
        DirectBookingOrder::query()
            ->whereIn('state', [
                DirectBookingOrderState::Expired,
                DirectBookingOrderState::Confirmed,
                DirectBookingOrderState::Canceled,
                DirectBookingOrderState::Refunded,
            ])->where('retained_until', '<=', now())->whereNull('pii_scrubbed_at')
            ->orderBy('id')->limit((int) $this->option('batch'))->get()
            ->each(function (DirectBookingOrder $order) use ($states, &$count): void {
                DB::transaction(function () use ($order, $states, &$count): void {
                    $locked = DirectBookingOrder::query()->lockForUpdate()->find($order->id);
                    if ($locked === null
                        || ! in_array($locked->state, [
                            DirectBookingOrderState::Expired,
                            DirectBookingOrderState::Confirmed,
                            DirectBookingOrderState::Canceled,
                            DirectBookingOrderState::Refunded,
                        ], true)
                        || $locked->retained_until->isFuture()
                        || $locked->pii_scrubbed_at !== null) {
                        return;
                    }
                    $this->cleanOrDeferProvisionalGuest($locked);
                    $result = $states->recordPiiScrubbed(
                        $locked,
                        'cleanup:'.$locked->public_reference.':'.$locked->state_version,
                    );
                    DB::table('direct_booking_order_consents')
                        ->where('direct_booking_order_id', $result->order->id)
                        ->update(['ip_prefix_hash' => null, 'updated_at' => now()]);
                    $count++;
                });
            });

        return $count;
    }

    private function cleanOrDeferProvisionalGuest(DirectBookingOrder $order): void
    {
        if ($order->state !== DirectBookingOrderState::Expired || $order->reservation_id === null) {
            return;
        }
        $reservation = $order->reservation()->lockForUpdate()->first();
        $guest = $reservation?->primary_guest_id === null
            ? null
            : Guest::query()->whereKey($reservation->primary_guest_id)->lockForUpdate()->first();
        $abandoned = $reservation !== null
            && $reservation->source === 'direct'
            && ($reservation->status === ReservationStatus::Draft
                || ($reservation->status === ReservationStatus::Hold && $reservation->hold_expires_at?->isPast() === true));
        $shared = $guest !== null && $this->guestHasOtherRecords($guest, $reservation);
        if (! $abandoned || $guest === null || $shared) {
            $order->forceFill(['guest_pii_cleanup_deferred_at' => now()])->save();

            return;
        }

        $guest->forceFill([
            'first_name' => 'Deleted guest',
            'last_name' => null,
            'email' => null,
            'phone' => null,
            'document_type' => null,
            'document_number' => null,
            'language' => null,
            'preferences' => null,
            'marketing_consent' => false,
        ])->save();
    }

    private function guestHasOtherRecords(Guest $guest, Reservation $reservation): bool
    {
        if ($guest->merged_into_id !== null
            || Guest::query()->where('merged_into_id', $guest->id)->exists()
            || $guest->reservations()->whereKeyNot($reservation->id)->exists()
            || $guest->companionReservations()->whereKeyNot($reservation->id)->exists()) {
            return true;
        }

        foreach ([
            ['communications', 'guest_id'],
            ['surveys', 'guest_id'],
            ['opportunities', 'guest_id'],
            ['crm_activities', 'guest_id'],
            ['guest_merge_aliases', 'guest_id'],
            ['generated_documents', 'guest_id'],
            ['document_generation_requests', 'guest_id'],
            ['guest_portal_profiles', 'guest_id'],
            ['guest_portal_access_tokens', 'guest_id'],
            ['guest_portal_acknowledgements', 'guest_id'],
            ['guest_payment_evidence', 'guest_id'],
            ['commercial_promotion_usages', 'guest_id'],
            ['voucher_redemptions', 'guest_id'],
            ['proposals', 'primary_guest_id'],
        ] as [$table, $column]) {
            if (DB::table($table)->where('tenant_id', $guest->tenant_id)->where($column, $guest->id)->exists()) {
                return true;
            }
        }

        return false;
    }
}
