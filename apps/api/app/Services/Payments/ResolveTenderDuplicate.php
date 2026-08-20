<?php

namespace App\Services\Payments;

use App\Data\Payments\FrontDeskPaymentInput;
use App\Enums\PaymentChannel;
use App\Models\PaymentTenderDetail;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

final class ResolveTenderDuplicate
{
    public function __construct(
        private readonly FinancialCommandExecutor $commands,
        private readonly FrontDeskPaymentGuard $guard,
        private readonly RecordFrontDeskPayment $payments,
    ) {}

    public function handle(User $actor, PaymentTenderDetail $detail, string $decision, string $reason, string $idempotencyKey, ?FrontDeskPaymentInput $corrected = null): PaymentTenderDetail
    {
        $tenantId = app(TenantContext::class)->tenant()->id;
        $payload = ['detail_id' => $detail->id, 'decision' => $decision, 'reason' => trim($reason), 'corrected' => $corrected?->checksumPayload()];

        /** @var PaymentTenderDetail $result */
        $result = $this->commands->run($tenantId, self::class, $idempotencyKey, $payload, function () use ($actor, $detail, $decision, $reason, $corrected): PaymentTenderDetail {
            $snapshot = PaymentTenderDetail::query()->findOrFail($detail->id);
            Reservation::query()->whereKey($snapshot->reservation_id)->lockForUpdate()->firstOrFail();
            $locked = PaymentTenderDetail::query()->lockForUpdate()->findOrFail($snapshot->id);
            $this->guard->resolveException($actor, $locked->property_id);
            if (! in_array($locked->state, ['duplicate_review', 'identity_exception', 'needs_corrected_identity'], true)) {
                throw ValidationException::withMessages(['state' => 'Only an unresolved tender exception may be reviewed.']);
            }
            if (! in_array($decision, ['confirmed_duplicate', 'needs_corrected_identity', 'dismissed_unposted', 'corrected_identity'], true) || trim($reason) === '') {
                throw ValidationException::withMessages(['decision' => 'Choose a supported resolution and record a reason.']);
            }
            if ($decision === 'corrected_identity') {
                if ($corrected === null || $corrected->reservationId !== $locked->reservation_id
                    || $corrected->channel !== PaymentChannel::ExternalTerminal || $corrected->amountMinor !== $locked->amount_minor
                    || $corrected->depositId !== $locked->deposit_id) {
                    throw ValidationException::withMessages(['corrected_identity' => 'The retry must preserve the draft reservation, deposit, channel, and amount.']);
                }
                $retried = $this->payments->handle($actor, $corrected);
                $locked->update([
                    'state' => 'corrected_identity_submitted',
                    'review_reason' => trim($reason),
                    'resolved_by' => $actor->id,
                    'resolved_at' => now(),
                ]);

                return $retried;
            }
            $locked->update([
                'state' => $decision,
                'review_reason' => trim($reason),
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
            ]);

            return $locked->fresh();
        });

        return $result->loadMissing('payment');
    }
}
