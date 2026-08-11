<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProgramResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'property_id' => $this->property_id,
            'name' => $this->name,
            'description' => $this->description,
            'display_color' => $this->display_color,
            'requires_accommodation' => $this->requires_accommodation,
            'default_duration_minutes' => $this->default_duration_minutes,
            'capacity' => $this->capacity,
            'price_minor' => $this->price_minor,
            'currency' => $this->currency,
            'is_active' => $this->is_active,
            'requirements' => $this->whenLoaded('requirements', fn () => $this->requirements->map(fn ($requirement): array => [
                'id' => $requirement->id,
                'resource_type' => $requirement->resource_type->value,
                'capabilities' => $requirement->capabilities ?? [],
                'languages' => $requirement->languages ?? [],
                'quantity' => $requirement->minimum_quantity,
                'guests_per_resource' => $requirement->guests_per_resource,
            ])),
        ];
    }
}
