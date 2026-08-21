<?php

namespace App\Services\DirectBooking;

use App\Enums\DirectBookingOrderState;
use App\Enums\DirectBookingTransitionAuthority;
use App\Enums\DocumentKind;
use App\Enums\ReservationStatus;
use App\Enums\TaskStatus;
use App\Models\DirectBookingOrder;
use App\Models\OperationalTask;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\ProviderRefund;
use App\Services\Documents\RequestDocumentGeneration;
use App\Services\ReservationService;
use Illuminate\Support\Facades\DB;

final class DirectBookingPaymentReconciler
{
    public function __construct(
        private readonly DirectBookingStateMachine $states,
        private readonly ReservationService $reservations,
        private readonly RequestDocumentGeneration $documents,
    ) {}

    public function approved(PaymentAttempt $attempt): void
    {
        DB::transaction(function () use ($attempt): void {
            $order = $this->lockedOrder($attempt);
            if ($order === null || $order->state === DirectBookingOrderState::Confirmed) {
                return;
            }
            $reservation = $order->reservation()->lockForUpdate()->first();
            if ($order->state !== DirectBookingOrderState::PaymentPending
                || $reservation === null
                || $reservation->status !== ReservationStatus::Hold
                || $reservation->hold_expires_at?->isFuture() !== true
                || $order->hold_expires_at?->isFuture() !== true
                || ! $reservation->hold_expires_at->equalTo($order->hold_expires_at)) {
                $this->markNeedsReview($order, $attempt, 'late_or_inventory_unavailable');

                return;
            }

            $paid = $this->states->transition(
                $order,
                DirectBookingOrderState::PaidPendingConfirmation,
                DirectBookingTransitionAuthority::ProviderLookup,
                $order->state_version,
                'provider-paid:'.$attempt->id,
                ['reason_code' => 'authoritative_provider_approval'],
            )->order;
            try {
                $this->reservations->confirm($reservation);
            } catch (\Throwable $exception) {
                report($exception);
                $this->states->transition(
                    $paid,
                    DirectBookingOrderState::PaidNeedsReview,
                    DirectBookingTransitionAuthority::Reservation,
                    $paid->state_version,
                    'confirmation-review:'.$attempt->id,
                    ['reason_code' => 'reservation_confirmation_failed'],
                );
                $this->financeTask($paid, $attempt, 'reservation_confirmation_failed');

                return;
            }
            $this->states->transition(
                $paid,
                DirectBookingOrderState::Confirmed,
                DirectBookingTransitionAuthority::Reservation,
                $paid->state_version,
                'provider-confirmed:'.$attempt->id,
                ['reason_code' => 'authoritative_payment_and_inventory_confirmed'],
            );
            $payment = $attempt->paymentRequest->payment_id === null
                ? null
                : Payment::query()->find($attempt->paymentRequest->payment_id);
            if ($payment !== null) {
                $this->documents->handleSystem(
                    $reservation->fresh(),
                    DocumentKind::PaymentReceipt,
                    app()->getLocale(),
                    'provider-payment-receipt:'.$payment->id,
                    $payment,
                );
            }
        }, 3);
    }

    public function needsReview(PaymentAttempt $attempt, string $reason): void
    {
        DB::transaction(function () use ($attempt, $reason): void {
            $order = $this->lockedOrder($attempt);
            if ($order === null) {
                return;
            }
            $this->markNeedsReview($order, $attempt, $reason);
        }, 3);
    }

    public function failed(PaymentAttempt $attempt, string $reason): void
    {
        DB::transaction(function () use ($attempt, $reason): void {
            $order = $this->lockedOrder($attempt);
            if ($order === null || $order->state !== DirectBookingOrderState::PaymentPending) {
                return;
            }
            $this->states->transition(
                $order,
                DirectBookingOrderState::PaymentFailed,
                DirectBookingTransitionAuthority::ProviderLookup,
                $order->state_version,
                'provider-failed:'.$attempt->id.':'.$reason,
                ['reason_code' => $reason],
            );
        }, 3);
    }

    public function refunded(ProviderRefund $refund): void
    {
        DB::transaction(function () use ($refund): void {
            $reservationId = $refund->reservationChange->reservation_id;
            $order = DirectBookingOrder::query()->where('reservation_id', $reservationId)->lockForUpdate()->first();
            if ($order === null || $order->state === DirectBookingOrderState::Refunded) {
                return;
            }
            if (! in_array($order->state, [DirectBookingOrderState::Confirmed, DirectBookingOrderState::PaidNeedsReview], true)) {
                return;
            }
            $payment = Payment::query()->whereKey($refund->payment_id)->lockForUpdate()->first();
            if ($payment?->status->value !== 'refunded') {
                return;
            }
            $this->states->transition(
                $order,
                DirectBookingOrderState::Refunded,
                DirectBookingTransitionAuthority::Refund,
                $order->state_version,
                'provider-refunded:'.$refund->id,
                ['reason_code' => 'authoritative_provider_refund_recovered'],
            );
        }, 3);
    }

    private function markNeedsReview(DirectBookingOrder $order, PaymentAttempt $attempt, string $reason): void
    {
        if (in_array($order->state, [DirectBookingOrderState::PaymentPending, DirectBookingOrderState::Expired], true)) {
            $order = $this->states->transition(
                $order,
                DirectBookingOrderState::PaidNeedsReview,
                DirectBookingTransitionAuthority::ProviderLookup,
                $order->state_version,
                'provider-review:'.$attempt->id,
                ['reason_code' => $reason],
            )->order;
        }
        $this->financeTask($order, $attempt, $reason);
    }

    private function financeTask(DirectBookingOrder $order, PaymentAttempt $attempt, string $reason): void
    {
        OperationalTask::query()->firstOrCreate([
            'property_id' => $order->property_id,
            'reservation_id' => $order->reservation_id,
            'title' => 'Review paid direct booking '.$order->public_reference,
        ], [
            'status' => TaskStatus::Todo,
            'priority' => 100,
            'description' => 'Finance must reconcile or refund an authoritative provider payment that could not safely confirm inventory.',
            'due_at' => now(),
            'metadata' => [
                'generated_from' => 'direct_booking_paid_needs_review',
                'payment_attempt_id' => $attempt->id,
                'safe_reason_code' => $reason,
            ],
        ]);
    }

    private function lockedOrder(PaymentAttempt $attempt): ?DirectBookingOrder
    {
        return DirectBookingOrder::query()
            ->where('payment_request_id', $attempt->payment_request_id)
            ->lockForUpdate()
            ->first();
    }
}
