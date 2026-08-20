<?php

namespace App\Models;

use App\Enums\AllocationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property AllocationStatus $status
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property int $quantity
 * @property string|null $requested_category_id
 * @property-read ResourceCategory|null $requestedCategory
 * @property-read \App\Models\Resource|null $resource
 * @property-read Reservation|null $reservation
 */
class Allocation extends TenantModel
{
    protected static function booted(): void
    {
        static::saving(function (Allocation $allocation): void {
            $reservation = Reservation::query()->find($allocation->reservation_id);
            if ($reservation === null || ($allocation->requested_category_id === null && $allocation->resource_id === null && $allocation->service_occurrence_id === null)) {
                throw new LogicException('An allocation requires a tenant reservation and a target.');
            }
            if ($allocation->resource_id !== null) {
                $resource = Resource::query()->whereKey($allocation->resource_id)
                    ->where('property_id', $reservation->property_id)
                    ->first();
                if ($resource === null) {
                    throw new LogicException('The allocation resource must belong to the reservation property and tenant.');
                }
                if ($allocation->requested_category_id !== null && $allocation->requested_category_id !== $resource->category_id) {
                    throw new LogicException('The assigned resource must match the requested category.');
                }
                $allocation->requested_category_id = $resource->category_id;
            }
            if ($allocation->requested_category_id !== null && ! ResourceCategory::query()
                ->whereKey($allocation->requested_category_id)
                ->where('property_id', $reservation->property_id)
                ->exists()) {
                throw new LogicException('The requested category must belong to the reservation property and tenant.');
            }
            if ($allocation->service_occurrence_id !== null && ! ServiceOccurrence::query()
                ->whereKey($allocation->service_occurrence_id)
                ->where('property_id', $reservation->property_id)
                ->exists()) {
                throw new LogicException('The allocation occurrence must belong to the reservation property and tenant.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => AllocationStatus::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'quantity' => 'integer',
            'revision' => 'integer',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** @return BelongsTo<\App\Models\Resource, $this> */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    /** @return BelongsTo<ResourceCategory, $this> */
    public function requestedCategory(): BelongsTo
    {
        return $this->belongsTo(ResourceCategory::class, 'requested_category_id');
    }

    public function requestedCategoryName(): ?string
    {
        if ($this->requested_category_id !== null) {
            return $this->requestedCategory->name;
        }

        return $this->resource?->categoryName();
    }

    public function assignedInstanceName(): ?string
    {
        return $this->resource?->name;
    }

    public function assignmentLabel(): string
    {
        return $this->assignedInstanceName() ?? (($this->requestedCategoryName() ?? 'Stay place').' requested');
    }

    /** @return BelongsTo<ServiceOccurrence, $this> */
    public function serviceOccurrence(): BelongsTo
    {
        return $this->belongsTo(ServiceOccurrence::class);
    }
}
