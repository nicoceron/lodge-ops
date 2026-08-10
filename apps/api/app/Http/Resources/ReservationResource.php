<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'property_id' => $this->property_id,
            'primary_guest_id' => $this->primary_guest_id,
            'confirmation_number' => $this->confirmation_number,
            'status' => $this->status->value,
            'source' => $this->source,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'adults' => $this->adults,
            'children' => $this->children,
            'currency' => $this->currency,
            'subtotal_minor' => $this->subtotal_minor,
            'tax_minor' => $this->tax_minor,
            'total_minor' => $this->total_minor,
            'revision' => $this->revision,
            'notes' => $this->notes,
            'confirmed_at' => $this->confirmed_at,
            'hold_expires_at' => $this->hold_expires_at,
            'primary_guest' => $this->whenLoaded('primaryGuest', fn () => new GuestResource($this->primaryGuest)),
            'guests' => GuestResource::collection($this->whenLoaded('guests')),
            'allocations' => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn ($allocation) => [
                'id' => $allocation->id,
                'resource_id' => $allocation->resource_id,
                'service_occurrence_id' => $allocation->service_occurrence_id,
                'status' => $allocation->status->value,
                'starts_at' => $allocation->starts_at,
                'ends_at' => $allocation->ends_at,
                'quantity' => $allocation->quantity,
                'resource' => $allocation->relationLoaded('resource') && $allocation->resource ? [
                    'id' => $allocation->resource->id,
                    'name' => $allocation->resource->name,
                    'code' => $allocation->resource->code,
                ] : null,
            ])),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
