<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LodgingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'property_id' => $this->property_id,
            'name' => $this->name,
            'code' => $this->code,
            'type' => $this->type->value,
            'capacity' => $this->capacity,
            'attributes' => $this->attributes,
            'is_active' => $this->is_active,
            'property' => $this->whenLoaded('property', fn () => ['id' => $this->property->id, 'name' => $this->property->name]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
