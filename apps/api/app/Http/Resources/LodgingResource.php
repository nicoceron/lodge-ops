<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Resource */
class LodgingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $category = $this->category;

        return [
            'id' => $this->id,
            'property_id' => $this->property_id,
            'category_id' => $this->category_id,
            'name' => $this->name,
            'code' => $this->code,
            'kind' => $category->kind->value,
            'category_slug' => $category->slug,
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'kind' => $category->kind->value,
                'counts_as_stay' => $category->counts_as_stay,
            ],
            'capacity' => $this->capacity,
            'user_id' => $this->user_id,
            'is_buyout' => $this->isBuyout(),
            'attributes' => $this->attributes,
            'is_active' => $this->is_active,
            'housekeeping_status' => $this->housekeeping_status?->value,
            'housekeeping_updated_at' => $this->housekeeping_updated_at,
            'housekeeping_updated_by' => $this->housekeeping_updated_by,
            'property' => $this->whenLoaded('property', fn () => ['id' => $this->property->id, 'name' => $this->property->name]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
