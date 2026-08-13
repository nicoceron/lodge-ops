<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ResourceBlockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'resource_id' => $this->resource_id,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'resource' => $this->whenLoaded('resource', fn ($resource) => [
                'id' => $resource->id,
                'name' => $resource->name,
                'code' => $resource->code,
                'category_id' => $resource->category_id,
                'kind' => $resource->category->kind->value,
                'category_slug' => $resource->category->slug,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
