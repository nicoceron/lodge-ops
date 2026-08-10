<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepositResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reservation_id' => $this->reservation_id,
            'payment_id' => $this->payment_id,
            'status' => $this->status->value,
            'currency' => $this->currency,
            'amount_minor' => $this->amount_minor,
            'due_at' => $this->due_at,
            'paid_at' => $this->paid_at,
            'waived_at' => $this->waived_at,
            'waiver_reason' => $this->waiver_reason,
            'created_at' => $this->created_at,
        ];
    }
}
