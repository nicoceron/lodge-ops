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
            'program_id' => $this->program_id,
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
            'program' => $this->whenLoaded('program', fn () => $this->program ? [
                'id' => $this->program->id,
                'name' => $this->program->name,
                'display_color' => $this->program->display_color,
            ] : null),
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
                'service_occurrence' => $allocation->relationLoaded('serviceOccurrence') && $allocation->serviceOccurrence ? [
                    'id' => $allocation->serviceOccurrence->id,
                    'program_id' => $allocation->serviceOccurrence->program_id,
                    'starts_at' => $allocation->serviceOccurrence->starts_at,
                    'ends_at' => $allocation->serviceOccurrence->ends_at,
                ] : null,
            ])),
            'status_history' => $this->whenLoaded('statusHistory', fn () => $this->statusHistory->map(fn ($history): array => [
                'id' => $history->id,
                'from_status' => $history->from_status?->value,
                'to_status' => $history->to_status->value,
                'changed_at' => $history->changed_at,
                'actor' => $history->relationLoaded('actor') && $history->actor ? [
                    'id' => $history->actor->id,
                    'name' => $history->actor->name,
                ] : null,
            ])),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
