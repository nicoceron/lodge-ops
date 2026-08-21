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
            'channel' => $this->channel,
            'payment_terminal_id' => $this->payment_terminal_id,
            'provider_pos_location_id' => $this->provider_pos_location_id,
            'provider_order_id' => $this->provider_order_id,
            'provider_transaction_id' => $this->provider_transaction_id,
            'provider_order_type' => $this->provider_order_type,
            'qr_mode' => $this->qr_mode,
            'qr_data' => $this->when($this->hasDisplayableQr(), fn (): ?string => $this->qr_data_ciphertext),
            'qr_data_checksum' => $this->qr_data_checksum,
            'order_expires_at' => $this->order_expires_at?->toIso8601String(),
            'provider_order_created_at' => $this->provider_order_created_at?->toIso8601String(),
            'provider_order_updated_at' => $this->provider_order_updated_at?->toIso8601String(),
            'action_required_at' => $this->action_required_at?->toIso8601String(),
            'checkout_url' => $this->when($request->user() === null, $this->hosted_checkout_url),
            'checkout_expires_at' => $this->checkout_expires_at?->toIso8601String(),
            'last_checked_at' => $this->last_checked_at?->toIso8601String(),
            'last_error' => $this->last_error,
        ];
    }
}
