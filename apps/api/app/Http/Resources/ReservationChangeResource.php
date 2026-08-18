<?php

namespace App\Http\Resources;

use App\Models\ReservationChange;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReservationChange */
class ReservationChangeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reservation_id' => $this->reservation_id,
            'parent_change_id' => $this->parent_change_id,
            'actor_id' => $this->actor_id,
            'type' => $this->type,
            'status' => $this->status,
            'currency' => $this->currency,
            'amount_minor' => $this->amount_minor,
            'reference' => $this->reference,
            'before_snapshot' => $this->before_snapshot,
            'after_snapshot' => $this->after_snapshot,
            'metadata' => $this->metadata,
            'occurred_at' => $this->occurred_at,
            'actor' => $this->whenLoaded('actor', fn () => $this->actor ? [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
            ] : null),
        ];
    }
}
