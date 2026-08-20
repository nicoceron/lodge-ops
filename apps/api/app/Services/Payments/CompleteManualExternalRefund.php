<?php

namespace App\Services\Payments;

use App\Enums\CashMovementType;
use App\Enums\CashShiftState;
use App\Enums\DocumentKind;
use App\Enums\PaymentChannel;
use App\Enums\PaymentEvidenceStatus;
use App\Enums\PaymentOrigin;
use App\Models\CashShift;
use App\Models\CashShiftMovement;
use App\Models\GuestPaymentEvidence;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationChange;
use App\Models\User;
use App\Services\CompleteRefund;
use App\Services\Documents\CanonicalJson;
use App\Services\Documents\RequestDocumentGeneration;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

final class CompleteManualExternalRefund
{
    public function __construct(
        private readonly FinancialCommandExecutor $commands,
        private readonly FrontDeskPaymentGuard $guard,
        private readonly CompleteRefund $refunds,
        private readonly CanonicalJson $canonical,
        private readonly RequestDocumentGeneration $documents,
    ) {}

    public function handle(User $actor, ReservationChange $request, string $executionReference, string $idempotencyKey, ?GuestPaymentEvidence $evidence = null, ?CashShift $cashShift = null): ReservationChange
    {
        $tenantId = app(TenantContext::class)->tenant()->id;
        $payload = ['refund_request_id' => $request->id, 'execution_reference' => trim($executionReference), 'evidence_id' => $evidence?->id, 'cash_shift_id' => $cashShift?->id];

        /** @var ReservationChange $result */
        $result = $this->commands->run($tenantId, self::class, $idempotencyKey, $payload, function () use ($actor, $request, $executionReference, $evidence, $cashShift, $payload, $idempotencyKey): ReservationChange {
            $snapshot = ReservationChange::query()->findOrFail($request->id);
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($snapshot->reservation_id);
            $payment = Payment::query()->with('tenderDetail')->lockForUpdate()->findOrFail((string) data_get($snapshot->metadata, 'payment_id'));
            $lockedRequest = ReservationChange::query()->lockForUpdate()->findOrFail($snapshot->id);
            $this->guard->resolveException($actor, $reservation->property_id);
            if ($payment->origin !== PaymentOrigin::Manual) {
                throw ValidationException::withMessages(['payment' => 'Provider-origin refunds cannot be manually completed.']);
            }
            $channel = $payment->channel;
            $executionReference = trim($executionReference);
            if ($executionReference === '') {
                throw ValidationException::withMessages(['execution_reference' => 'Record the external or cash-disbursement execution reference.']);
            }
            if ($channel !== PaymentChannel::Cash) {
                $lockedEvidence = $evidence === null ? null : GuestPaymentEvidence::query()->lockForUpdate()->findOrFail($evidence->id);
                if ($lockedEvidence === null || ! in_array($lockedEvidence->scan_state, ['accepted', 'clean'], true)
                    || $lockedEvidence->status !== PaymentEvidenceStatus::Approved
                    || $lockedEvidence->refund_change_id !== $lockedRequest->id
                    || $lockedEvidence->reservation_id !== $payment->reservation_id) {
                    throw ValidationException::withMessages(['evidence_id' => 'Approved, clean private execution evidence for this refund request is required.']);
                }
            }

            $shift = null;
            if ($channel === PaymentChannel::Cash) {
                $shift = $cashShift === null ? null : CashShift::query()->lockForUpdate()->findOrFail($cashShift->id);
                if ($shift === null || $shift->property_id !== $reservation->property_id || $shift->currency !== $payment->currency || $shift->state !== CashShiftState::Open
                    || $shift->cashier_id !== $actor->id) {
                    throw ValidationException::withMessages(['cash_shift_id' => 'A matching authorized open cash shift is required before cash is marked dispensed.']);
                }
            }

            $completed = $this->refunds->handle($lockedRequest, $executionReference, $actor->id);
            if ($shift !== null) {
                CashShiftMovement::query()->firstOrCreate(
                    ['refund_change_id' => $completed->id, 'type' => CashMovementType::Refund->value],
                    [
                        'property_id' => $shift->property_id,
                        'cash_shift_id' => $shift->id,
                        'amount_minor' => -$completed->amount_minor,
                        'currency' => $shift->currency,
                        'reason' => 'Cash refund '.$executionReference,
                        'recorded_by' => $actor->id,
                        'occurred_at' => now(),
                        'command_key' => 'cash-refund:'.$idempotencyKey,
                        'command_checksum' => $this->canonical->checksum($payload),
                    ],
                );
            }
            if ($evidence !== null) {
                GuestPaymentEvidence::query()->whereKey($evidence->id)->update([
                    'payment_id' => $payment->id,
                    'refund_change_id' => $completed->id,
                    'tender_detail_id' => $payment->tenderDetail?->id,
                ]);
            }
            $this->documents->handleSystem($reservation, DocumentKind::RefundReceipt, app()->getLocale(), 'manual-refund-receipt:'.$completed->id, $payment, $completed);

            return $completed;
        });

        return $result;
    }
}
