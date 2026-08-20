<?php

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payment */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $finance = $request->user()?->can('viewFinance', Payment::class) === true;

        return [
            'id' => $this->id,
            'reservation_id' => $this->reservation_id,
            'status' => $this->status->value,
            'method' => $this->method,
            'channel' => $this->channel->value,
            'entry_mode' => $this->entry_mode->value,
            'origin' => $this->origin->value,
            'provider' => $finance ? $this->provider : null,
            'provider_reference' => $finance ? $this->provider_reference : null,
            'currency' => $this->currency,
            'amount_minor' => $this->amount_minor,
            'processed_at' => $this->processed_at,
            'reconciled_at' => $this->reconciled_at,
            'reversed_at' => $this->reversed_at,
            'reversal_reason' => $this->reversal_reason,
            'metadata' => $finance ? $this->metadata : null,
            'created_at' => $this->created_at,
        ];
    }
}
