<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reservation_id' => $this->reservation_id,
            'status' => $this->status->value,
            'method' => $this->method,
            'provider' => $this->provider,
            'provider_reference' => $this->provider_reference,
            'currency' => $this->currency,
            'amount_minor' => $this->amount_minor,
            'processed_at' => $this->processed_at,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
        ];
    }
}
