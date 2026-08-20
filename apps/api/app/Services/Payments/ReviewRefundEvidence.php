<?php

namespace App\Services\Payments;

use App\Enums\PaymentEvidenceStatus;
use App\Models\GuestPaymentEvidence;
use App\Models\Reservation;
use App\Models\ReservationChange;
use App\Models\User;
use App\Services\Automation\OutboxRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

final class ReviewRefundEvidence
{
    public function __construct(
        private readonly FinancialCommandExecutor $commands,
        private readonly FrontDeskPaymentGuard $guard,
        private readonly OutboxRecorder $outbox,
    ) {}

    public function handle(User $actor, GuestPaymentEvidence $evidence, string $decision, string $reason, string $idempotencyKey): GuestPaymentEvidence
    {
        $tenantId = app(TenantContext::class)->tenant()->id;
        $payload = ['evidence_id' => $evidence->id, 'decision' => $decision, 'reason' => trim($reason)];

        /** @var GuestPaymentEvidence $result */
        $result = $this->commands->run($tenantId, self::class, $idempotencyKey, $payload, function () use ($actor, $evidence, $decision, $reason): GuestPaymentEvidence {
            $snapshot = GuestPaymentEvidence::query()->findOrFail($evidence->id);
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($snapshot->reservation_id);
            $locked = GuestPaymentEvidence::query()->lockForUpdate()->findOrFail($snapshot->id);
            $this->guard->resolveException($actor, $reservation->property_id);
            $reason = trim($reason);
            if ($locked->refund_change_id === null || $reason === '' || ! in_array($decision, ['approved', 'rejected'], true)) {
                throw ValidationException::withMessages(['decision' => 'Refund evidence requires an approve/reject decision and a reason.']);
            }
            $refund = ReservationChange::query()->whereKey($locked->refund_change_id)->lockForUpdate()->firstOrFail();
            if ($refund->reservation_id !== $reservation->id || ! in_array($refund->type, ['refund_requested', 'refund_completed'], true)) {
                throw ValidationException::withMessages(['evidence' => 'The evidence is not linked to a refund for this reservation.']);
            }
            $status = PaymentEvidenceStatus::from($decision);
            if ($locked->status === $status) {
                return $locked;
            }
            if ($locked->status === PaymentEvidenceStatus::Approved || $locked->status === PaymentEvidenceStatus::Rejected) {
                throw ValidationException::withMessages(['status' => 'A final refund-evidence decision cannot be changed.']);
            }
            if ($status === PaymentEvidenceStatus::Approved && ! in_array($locked->scan_state, ['accepted', 'clean'], true)) {
                throw ValidationException::withMessages(['scan_state' => 'Only successfully scanned evidence may be approved.']);
            }
            $locked->update([
                'status' => $status,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'decided_at' => now(),
                'reviewer_note' => $reason,
                'requested_information_note' => null,
            ]);
            $this->outbox->record('guest_payment_evidence', $locked->id, 'refund_evidence.'.$status->value, [
                'evidence_id' => $locked->id,
                'refund_change_id' => $refund->id,
                'reservation_id' => $reservation->id,
            ]);

            return $locked->fresh();
        });

        return $result;
    }
}
