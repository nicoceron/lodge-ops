<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceOccurrenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'program_id' => $this->program_id,
            'property_id' => $this->property_id,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'capacity' => $this->capacity,
            'is_cancelled' => $this->is_cancelled,
            'meeting_point' => $this->meeting_point,
            'program' => $this->whenLoaded('program', fn () => [
                'id' => $this->program->id,
                'name' => $this->program->name,
                'display_color' => $this->program->display_color,
            ]),
            'allocated_quantity' => $this->when(isset($this->allocated_quantity), $this->allocated_quantity),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
