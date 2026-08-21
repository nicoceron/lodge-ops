<?php

namespace App\Http\Resources;

use App\Models\Allocation;
use App\Models\ResourceCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property string|null $requested_category_id
 * @property ResourceCategory|null $requestedCategory
 *
 * @mixin Allocation
 */
class AllocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reservation_id' => $this->reservation_id,
            'requested_category_id' => $this->requested_category_id,
            'resource_id' => $this->resource_id,
            'service_occurrence_id' => $this->service_occurrence_id,
            'status' => $this->status->value,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'quantity' => $this->quantity,
            'revision' => $this->revision,
            'requested_category' => $this->whenLoaded('requestedCategory', fn () => $this->requestedCategory ? [
                'id' => $this->requestedCategory->id,
                'name' => $this->requestedCategory->name,
                'slug' => $this->requestedCategory->slug,
                'kind' => $this->requestedCategory->kind->value,
                'counts_as_stay' => $this->requestedCategory->counts_as_stay,
            ] : null),
            'resource' => $this->whenLoaded('resource', fn ($resource) => $resource ? [
                'id' => $resource->id,
                'name' => $resource->name,
                'code' => $resource->code,
                'category_id' => $resource->category_id,
                'kind' => $resource->category->kind->value,
                'category_slug' => $resource->category->slug,
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
