<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FolioLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reservation_id' => $this->reservation_id,
            'payment_id' => $this->payment_id,
            'reverses_folio_line_id' => $this->reverses_folio_line_id,
            'type' => $this->type->value,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_amount_minor' => $this->unit_amount_minor,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'posted_at' => $this->posted_at,
            'created_by' => $this->created_by,
            'metadata' => $this->metadata,
        ];
    }
}
