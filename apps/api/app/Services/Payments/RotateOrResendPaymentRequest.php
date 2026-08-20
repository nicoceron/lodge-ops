<?php

namespace App\Services\Payments;

use App\Data\Payments\IssuedPaymentRequest;
use App\Enums\PaymentRequestState;
use App\Exceptions\CommercialWorkflowException as DomainException;
use App\Models\PaymentRequest;
use App\Services\Automation\OutboxRecorder;
use App\Services\Communications\QueuePaymentRequestCommunication;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RotateOrResendPaymentRequest
{
    public function __construct(
        private readonly OutboxRecorder $outbox,
        private readonly QueuePaymentRequestCommunication $communications,
    ) {}

    public function handle(PaymentRequest $request, bool $rotate, ?int $actorId): IssuedPaymentRequest
    {
        return DB::transaction(function () use ($request, $rotate, $actorId): IssuedPaymentRequest {
            $locked = PaymentRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($locked->state !== PaymentRequestState::Open || $locked->expires_at->isPast()) {
                throw new DomainException('Only an unexpired open payment request can be sent.');
            }
            if (! $rotate) {
                throw new DomainException('Secure resend requires token rotation because plaintext access tokens are never retained.');
            }
            $token = Str::random(64);
            $locked->update(['access_token_hash' => hash('sha256', $token)]);
            $this->outbox->record('payment_request', $locked->id, 'payment_request.rotated', [
                'payment_request_id' => $locked->id,
                'actor_id' => $actorId,
            ]);
            $this->communications->handle($locked, $token, $actorId);

            return new IssuedPaymentRequest($locked->fresh(), $token);
        }, 3);
    }
}
