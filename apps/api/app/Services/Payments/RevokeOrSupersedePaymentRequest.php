<?php

namespace App\Services\Payments;

use App\Enums\PaymentRequestState;
use App\Exceptions\CommercialWorkflowException as DomainException;
use App\Models\PaymentRequest;
use App\Models\Reservation;
use App\Services\Automation\OutboxRecorder;
use Illuminate\Support\Facades\DB;

final class RevokeOrSupersedePaymentRequest
{
    public function __construct(private readonly OutboxRecorder $outbox) {}

    public function handle(PaymentRequest $request, string $reason, ?int $actorId, bool $superseded = false): PaymentRequest
    {
        return DB::transaction(function () use ($request, $reason, $actorId, $superseded): PaymentRequest {
            Reservation::query()->lockForUpdate()->findOrFail($request->reservation_id);
            $locked = PaymentRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($locked->state === PaymentRequestState::Paid) {
                throw new DomainException('A paid payment request cannot be revoked.');
            }
            if (! in_array($locked->state, [PaymentRequestState::Open, PaymentRequestState::Processing], true)) {
                return $locked;
            }
            if (trim($reason) === '') {
                throw new DomainException('A revocation reason is required.');
            }
            $locked->update([
                'state' => $superseded ? PaymentRequestState::Superseded : PaymentRequestState::Revoked,
                'revoked_at' => now(),
                'revoked_by' => $actorId,
                'revocation_reason' => trim($reason),
            ]);
            $locked->attempts()->whereIn('state', ['creating', 'checkout_ready', 'pending'])->update([
                'state' => 'expired',
                'last_error' => $superseded ? 'Payment request superseded.' : 'Payment request revoked.',
                'last_processed_at' => now(),
            ]);
            $this->outbox->record('payment_request', $locked->id, $superseded ? 'payment_request.superseded' : 'payment_request.revoked', [
                'payment_request_id' => $locked->id,
                'reason' => trim($reason),
            ]);

            return $locked->fresh();
        }, 3);
    }
}
