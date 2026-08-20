<?php

namespace App\Console\Commands;

use App\Enums\DirectBookingOrderState;
use App\Enums\DirectBookingTransitionAuthority;
use App\Models\DirectBookingOrder;
use App\Models\Tenant;
use App\Services\DirectBooking\DirectBookingStateMachine;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MaintainDirectBookingOrders extends Command
{
    protected $signature = 'direct-booking:maintain {--tenant=} {--batch=100} {--cleanup}';

    protected $description = 'Expire direct-booking sessions and scrub retained session PII with versioned outcomes.';

    public function handle(DirectBookingStateMachine $states): int
    {
        $expired = 0;
        $scrubbed = 0;
        Tenant::query()->when($this->option('tenant'), fn ($query, $id) => $query->whereKey($id))->get()
            ->each(function (Tenant $tenant) use ($states, &$expired, &$scrubbed): void {
                app(TenantContext::class)->set($tenant);
                if ($this->option('cleanup')) {
                    $scrubbed += $this->scrub($states);
                } else {
                    $expired += $this->expire($states);
                }
            });
        app(TenantContext::class)->clear();
        $this->info("Expired {$expired}; scrubbed {$scrubbed} direct-booking order(s).");

        return self::SUCCESS;
    }

    private function expire(DirectBookingStateMachine $states): int
    {
        $count = 0;
        DirectBookingOrder::query()
            ->whereIn('state', [
                DirectBookingOrderState::Started,
                DirectBookingOrderState::Quoted,
                DirectBookingOrderState::Held,
                DirectBookingOrderState::PaymentPending,
                DirectBookingOrderState::AwaitingManualPayment,
                DirectBookingOrderState::EvidencePending,
                DirectBookingOrderState::FinanceReview,
                DirectBookingOrderState::PaymentFailed,
                DirectBookingOrderState::EvidenceRejected,
            ])->where('expires_at', '<=', now())->orderBy('id')->limit((int) $this->option('batch'))->get()
            ->each(function (DirectBookingOrder $order) use ($states, &$count): void {
                $states->transition(
                    $order,
                    DirectBookingOrderState::Expired,
                    DirectBookingTransitionAuthority::Scheduler,
                    $order->state_version,
                    'expire:'.$order->public_reference.':'.$order->state_version,
                    ['scheduler_outcome' => 'session_expired'],
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
            ])->where('retained_until', '<=', now())->where(function ($query): void {
                $query->whereNotNull('guest_contact_encrypted')->orWhereNotNull('attribution')->orWhereNull('revoked_at');
            })->orderBy('id')->limit((int) $this->option('batch'))->get()
            ->each(function (DirectBookingOrder $order) use ($states, &$count): void {
                DB::transaction(function () use ($order, $states, &$count): void {
                    $result = $states->transition(
                        $order,
                        $order->state,
                        DirectBookingTransitionAuthority::Scheduler,
                        $order->state_version,
                        'cleanup:'.$order->public_reference.':'.$order->state_version,
                        ['scheduler_outcome' => 'session_pii_scrubbed'],
                    );
                    $result->order->forceFill([
                        'token_hash' => hash('sha256', 'purged:'.Str::random(64)),
                        'guest_contact_encrypted' => null,
                        'guest_contact_checksum' => null,
                        'attribution' => null,
                        'ip_prefix_hash' => null,
                        'revoked_at' => now(),
                    ])->save();
                    DB::table('direct_booking_order_consents')
                        ->where('direct_booking_order_id', $result->order->id)
                        ->update(['ip_prefix_hash' => null, 'updated_at' => now()]);
                    $count++;
                });
            });

        return $count;
    }
}
