<?php

namespace App\Http\Resources;

use App\Models\PaymentRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PaymentRequest */
class PaymentRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'property_id' => $this->property_id,
            'reservation_id' => $this->reservation_id,
            'deposit_id' => $this->deposit_id,
            'purpose' => $this->purpose->value,
            'initiation_mode' => $this->initiation_mode,
            'state' => $this->state->value,
            'source_amount_minor' => $this->source_amount_minor,
            'source_currency' => $this->source_currency,
            'charge_currency' => $this->charge_currency,
            'expires_at' => $this->expires_at->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'payment_id' => $this->payment_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
