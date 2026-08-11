<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AllocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reservation_id' => $this->reservation_id,
            'resource_id' => $this->resource_id,
            'service_occurrence_id' => $this->service_occurrence_id,
            'status' => $this->status->value,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'quantity' => $this->quantity,
            'resource' => $this->whenLoaded('resource', fn ($resource) => $resource ? [
                'id' => $resource->id,
                'name' => $resource->name,
                'code' => $resource->code,
                'type' => $resource->type->value,
                'is_buyout' => $resource->isBuyout(),
            ] : null),
            'service_occurrence' => $this->whenLoaded('serviceOccurrence', fn () => $this->serviceOccurrence ? [
                'id' => $this->serviceOccurrence->id,
                'program_id' => $this->serviceOccurrence->program_id,
                'starts_at' => $this->serviceOccurrence->starts_at,
                'ends_at' => $this->serviceOccurrence->ends_at,
            ] : null),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
