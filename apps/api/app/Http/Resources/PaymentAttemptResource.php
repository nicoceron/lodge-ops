<?php

namespace App\Http\Resources;

use App\Models\PaymentAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PaymentAttempt */
class PaymentAttemptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_request_id' => $this->payment_request_id,
            'provider' => $this->provider,
            'environment' => $this->environment,
            'external_reference' => $this->external_reference,
            'state' => $this->state->value,
            'source_amount_minor' => $this->source_amount_minor,
            'source_currency' => $this->source_currency,
            'charge_amount_minor' => $this->charge_amount_minor,
            'charge_currency' => $this->charge_currency,
            'conversion_snapshot' => $this->conversion_snapshot,
            'provider_status' => $this->provider_status,
            'provider_status_detail' => $this->provider_status_detail,
            'checkout_url' => $this->when($request->user() === null, $this->hosted_checkout_url),
            'checkout_expires_at' => $this->checkout_expires_at?->toIso8601String(),
            'last_checked_at' => $this->last_checked_at?->toIso8601String(),
        ];
    }
}
