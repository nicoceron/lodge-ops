<?php

namespace App\Services\DirectBooking;

use App\Enums\DirectBookingOrderState;
use App\Enums\DirectBookingTransitionAuthority;
use App\Enums\ReservationStatus;
use App\Enums\TaskStatus;
use App\Models\DirectBookingOrder;
use App\Models\GuestPaymentEvidence;
use App\Models\OperationalTask;
use App\Services\ReservationService;
use Illuminate\Support\Facades\DB;

final class DirectBookingManualReviewReconciler
{
    public function __construct(
        private readonly DirectBookingStateMachine $states,
        private readonly ReservationService $reservations,
    ) {}

    public function prepareApproval(GuestPaymentEvidence $evidence): void
    {
        DB::transaction(function () use ($evidence): void {
            $order = DirectBookingOrder::query()->where('reservation_id', $evidence->reservation_id)->lockForUpdate()->first();
            if ($order?->state !== DirectBookingOrderState::EvidencePending) {
                return;
            }
            $this->states->transition(
                $order,
                DirectBookingOrderState::FinanceReview,
                DirectBookingTransitionAuthority::EvidenceScanner,
                $order->state_version,
                'evidence-scanned:'.$evidence->id,
                ['reason_code' => 'private_evidence_scanned'],
            );
        }, 3);
    }

    public function approved(GuestPaymentEvidence $evidence): void
    {
        DB::transaction(function () use ($evidence): void {
            $order = DirectBookingOrder::query()->where('reservation_id', $evidence->reservation_id)->lockForUpdate()->first();
            if ($order?->state !== DirectBookingOrderState::FinanceReview) {
                return;
            }
            $reservation = $order->reservation()->lockForUpdate()->first();
            if ($reservation === null || $reservation->status !== ReservationStatus::Hold
                || $reservation->hold_expires_at?->isFuture() !== true
                || $order->hold_expires_at?->isFuture() !== true) {
                OperationalTask::query()->firstOrCreate([
                    'property_id' => $order->property_id,
                    'reservation_id' => $order->reservation_id,
                    'title' => 'Review late manual payment '.$order->public_reference,
                ], [
                    'status' => TaskStatus::Todo,
                    'priority' => 100,
                    'description' => 'Finance accepted bank-transfer evidence after the inventory hold ended. Recover inventory or execute and record a refund.',
                    'due_at' => now(),
                    'metadata' => ['generated_from' => 'direct_booking_late_manual_payment'],
                ]);

                return;
            }
            $this->reservations->confirm($reservation);
            $this->states->transition(
                $order,
                DirectBookingOrderState::Confirmed,
                DirectBookingTransitionAuthority::Finance,
                $order->state_version,
                'manual-confirmed:'.$evidence->id,
                ['reason_code' => 'finance_approved_payment_and_inventory'],
            );
        }, 3);
    }

    public function rejected(GuestPaymentEvidence $evidence): void
    {
        DB::transaction(function () use ($evidence): void {
            $order = DirectBookingOrder::query()->where('reservation_id', $evidence->reservation_id)->lockForUpdate()->first();
            if ($order === null || ! in_array($order->state, [DirectBookingOrderState::EvidencePending, DirectBookingOrderState::FinanceReview], true)) {
                return;
            }
            $this->states->transition(
                $order,
                DirectBookingOrderState::EvidenceRejected,
                $order->state === DirectBookingOrderState::EvidencePending
                    ? DirectBookingTransitionAuthority::EvidenceScanner
                    : DirectBookingTransitionAuthority::Finance,
                $order->state_version,
                'manual-rejected:'.$evidence->id,
                ['reason_code' => 'finance_rejected_evidence'],
            );
        }, 3);
    }
}
