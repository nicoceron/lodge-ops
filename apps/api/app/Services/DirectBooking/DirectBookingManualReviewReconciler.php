<?php

namespace App\Services\DirectBooking;

use App\Enums\DirectBookingOrderState;
use App\Enums\DirectBookingTransitionAuthority;
use App\Enums\DocumentKind;
use App\Enums\PaymentRequestState;
use App\Enums\ReservationStatus;
use App\Enums\TaskStatus;
use App\Models\DirectBookingOrder;
use App\Models\GuestPaymentEvidence;
use App\Models\OperationalTask;
use App\Models\Payment;
use App\Services\Documents\RequestDocumentGeneration;
use App\Services\ReservationService;
use Illuminate\Support\Facades\DB;

final class DirectBookingManualReviewReconciler
{
    public function __construct(
        private readonly DirectBookingStateMachine $states,
        private readonly ReservationService $reservations,
        private readonly RequestDocumentGeneration $documents,
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
            $payment = $evidence->payment_id === null ? null : Payment::query()->whereKey($evidence->payment_id)->lockForUpdate()->first();
            if ($payment !== null && $order->payment_request_id !== null) {
                $request = $order->paymentRequest()->lockForUpdate()->first();
                if ($request !== null && in_array($request->state, [PaymentRequestState::Open, PaymentRequestState::Processing], true)) {
                    $request->forceFill([
                        'payment_id' => $payment->id,
                        'state' => PaymentRequestState::Paid,
                        'paid_at' => $payment->processed_at ?? now(),
                    ])->save();
                }
            }
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
            $this->documents->handleSystem(
                $reservation->fresh(),
                DocumentKind::ReservationConfirmation,
                app()->getLocale(),
                'direct-booking-confirmation:'.$order->id,
            );
            if ($payment !== null) {
                $this->documents->handleSystem(
                    $reservation->fresh(),
                    DocumentKind::PaymentReceipt,
                    app()->getLocale(),
                    'manual-payment-receipt:'.$payment->id,
                    $payment,
                );
            }
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
