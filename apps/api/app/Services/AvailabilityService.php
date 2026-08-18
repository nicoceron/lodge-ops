<?php

namespace App\Services;

use App\Enums\AllocationStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\AllocationConflictException;
use App\Models\Allocation;
use App\Models\Property;
use App\Models\Resource;
use App\Models\ResourceBlock;
use App\Models\ResourceCategory;
use App\Models\ServiceOccurrence;

class AvailabilityService
{
    public function assertAvailable(Allocation $allocation): void
    {
        if ($allocation->starts_at >= $allocation->ends_at) {
            throw new AllocationConflictException('Allocation end must be after its start.', $allocation->resource_id);
        }

        if ($allocation->resource_id !== null) {
            $resource = Resource::query()->findOrFail($allocation->resource_id);
            // A property lock serializes ordinary and buyout allocations, which otherwise lock
            // different resource rows and could both pass their conflict checks concurrently.
            Property::query()->whereKey($resource->property_id)->lockForUpdate()->firstOrFail();
            $resource = Resource::query()->lockForUpdate()->findOrFail($allocation->resource_id);

            $propertyResourceIds = Resource::query()->where('property_id', $resource->property_id)->pluck('id');
            $buyoutResources = Resource::query()
                ->where('property_id', $resource->property_id)
                ->get()
                ->filter(fn (Resource $candidate): bool => $candidate->isBuyout());
            $buyoutResourceIds = $buyoutResources->pluck('id');
            $buyoutCategoryIds = $buyoutResources->pluck('category_id')->unique();
            $conflictingResourceIds = $resource->isBuyout()
                ? $propertyResourceIds
                : $buyoutResourceIds->push($resource->id)->unique();

            $block = ResourceBlock::query()
                ->whereIn('resource_id', $conflictingResourceIds)
                ->where('starts_at', '<', $allocation->ends_at)
                ->where('ends_at', '>', $allocation->starts_at)
                ->lockForUpdate()
                ->first();

            if ($block !== null) {
                throw new AllocationConflictException('The resource is blocked for the requested interval.', $allocation->resource_id, $block->id);
            }

            $conflicts = Allocation::query()
                ->where(function ($query) use ($buyoutCategoryIds, $conflictingResourceIds, $resource): void {
                    $query->whereIn('resource_id', $conflictingResourceIds);
                    if ($resource->isBuyout()) {
                        $query->orWhere(function ($query) use ($resource): void {
                            $query->whereNull('resource_id')
                                ->whereHas('requestedCategory', fn ($category) => $category->where('property_id', $resource->property_id));
                        });
                    } else {
                        $query->orWhere(function ($query) use ($buyoutCategoryIds): void {
                            $query->whereNull('resource_id')->whereIn('requested_category_id', $buyoutCategoryIds);
                        });
                    }
                })
                ->whereKeyNot($allocation->id)
                ->where(function ($query) use ($allocation): void {
                    $query->where('status', AllocationStatus::Confirmed)
                        ->orWhere(function ($query): void {
                            $query->where('status', AllocationStatus::Tentative)
                                ->whereHas('reservation', fn ($reservation) => $reservation
                                    ->where('status', ReservationStatus::Hold)
                                    ->where('hold_expires_at', '>', now()));
                        })
                        ->orWhere(function ($query) use ($allocation): void {
                            $query->where('reservation_id', $allocation->reservation_id)
                                ->where('status', AllocationStatus::Tentative);
                        });
                })
                ->where('starts_at', '<', $allocation->ends_at)
                ->where('ends_at', '>', $allocation->starts_at)
                ->lockForUpdate()
                ->get();

            $exclusiveConflict = $resource->isBuyout()
                ? $conflicts->first()
                : $conflicts->first(fn (Allocation $candidate): bool => $buyoutResourceIds->contains($candidate->resource_id));
            if ($exclusiveConflict !== null) {
                throw new AllocationConflictException('A property buyout conflicts with this allocation.', $allocation->resource_id, $exclusiveConflict->id);
            }

            $allocatedQuantity = $conflicts->where('resource_id', $resource->id)->sum('quantity');
            $isExclusiveStayPlace = $resource->category()->where('counts_as_stay', true)->exists();
            if (($isExclusiveStayPlace && $allocatedQuantity > 0)
                || (! $isExclusiveStayPlace && $allocatedQuantity + $allocation->quantity > $resource->capacity)) {
                throw new AllocationConflictException(
                    'The resource has insufficient remaining capacity for the requested interval.',
                    $allocation->resource_id,
                    $conflicts->where('resource_id', $resource->id)->first()?->id,
                );
            }
        }

        if ($allocation->service_occurrence_id !== null) {
            $occurrence = ServiceOccurrence::query()->lockForUpdate()->findOrFail($allocation->service_occurrence_id);
            $allocated = Allocation::query()
                ->where('service_occurrence_id', $occurrence->id)
                ->whereKeyNot($allocation->id)
                ->where(function ($query) use ($allocation): void {
                    $query->where('status', AllocationStatus::Confirmed)
                        ->orWhere(function ($query): void {
                            $query->where('status', AllocationStatus::Tentative)
                                ->whereHas('reservation', fn ($reservation) => $reservation
                                    ->where('status', ReservationStatus::Hold)
                                    ->where('hold_expires_at', '>', now()));
                        })
                        ->orWhere('reservation_id', $allocation->reservation_id);
                })
                ->sum('quantity');

            if ($occurrence->is_cancelled || $allocated + $allocation->quantity > $occurrence->capacity) {
                throw new AllocationConflictException('The service occurrence has insufficient capacity.', conflictingId: $occurrence->id);
            }
        }

        if ($allocation->requested_category_id !== null) {
            $this->assertCategoryCapacity($allocation);
        }
    }

    private function assertCategoryCapacity(Allocation $allocation): void
    {
        $category = ResourceCategory::query()->findOrFail($allocation->requested_category_id);
        Property::query()->whereKey($category->property_id)->lockForUpdate()->firstOrFail();
        $category = ResourceCategory::query()->lockForUpdate()->findOrFail($category->id);
        $resources = Resource::query()
            ->where('category_id', $category->id)
            ->where('is_active', true)
            ->lockForUpdate()
            ->get(['id', 'property_id', 'capacity', 'is_buyout', 'attributes']);

        if ($resources->isEmpty()) {
            throw new AllocationConflictException(
                'The requested category has no active inventory.',
                $allocation->resource_id,
            );
        }

        $propertyId = $category->property_id;
        if ($resources->first()->isBuyout()) {
            $this->assertExclusiveCategoryAvailable($allocation, $propertyId);

            return;
        }

        $buyoutResources = Resource::query()
            ->where('property_id', $propertyId)
            ->get()
            ->filter(fn (Resource $resource): bool => $resource->isBuyout());
        $buyoutResourceIds = $buyoutResources->pluck('id');
        $buyoutCategoryIds = $buyoutResources->pluck('category_id')->unique();
        $buyoutBlock = ResourceBlock::query()
            ->whereIn('resource_id', $buyoutResourceIds)
            ->where('starts_at', '<', $allocation->ends_at)
            ->where('ends_at', '>', $allocation->starts_at)
            ->lockForUpdate()
            ->first();
        if ($buyoutBlock !== null) {
            throw new AllocationConflictException(
                'A property buyout block conflicts with the requested category.',
                $allocation->resource_id,
                $buyoutBlock->id,
            );
        }

        $buyoutAllocation = Allocation::query()
            ->where(function ($query) use ($buyoutCategoryIds, $buyoutResourceIds): void {
                $query->whereIn('resource_id', $buyoutResourceIds)
                    ->orWhere(function ($query) use ($buyoutCategoryIds): void {
                        $query->whereNull('resource_id')->whereIn('requested_category_id', $buyoutCategoryIds);
                    });
            })
            ->whereKeyNot($allocation->id)
            ->where('starts_at', '<', $allocation->ends_at)
            ->where('ends_at', '>', $allocation->starts_at)
            ->where(fn ($query) => $this->activeAllocationScope($query, $allocation))
            ->lockForUpdate()
            ->first();
        if ($buyoutAllocation !== null) {
            throw new AllocationConflictException(
                'A property buyout conflicts with the requested category.',
                $allocation->resource_id,
                $buyoutAllocation->id,
            );
        }

        $resourceIds = $resources->pluck('id');
        $blockedIds = ResourceBlock::query()
            ->whereIn('resource_id', $resourceIds)
            ->where('starts_at', '<', $allocation->ends_at)
            ->where('ends_at', '>', $allocation->starts_at)
            ->lockForUpdate()
            ->pluck('resource_id')
            ->unique();
        $availableResources = $resources->reject(fn (Resource $resource): bool => $blockedIds->contains($resource->id));
        $availableCapacity = $category->counts_as_stay
            ? $availableResources->count()
            : (int) $availableResources->sum('capacity');

        $reserved = (int) Allocation::query()
            ->where('requested_category_id', $allocation->requested_category_id)
            ->whereKeyNot($allocation->id)
            ->where('starts_at', '<', $allocation->ends_at)
            ->where('ends_at', '>', $allocation->starts_at)
            ->where(fn ($query) => $this->activeAllocationScope($query, $allocation))
            ->lockForUpdate()
            ->get(['quantity'])
            ->sum('quantity');

        if ($reserved + $allocation->quantity > $availableCapacity) {
            throw new AllocationConflictException(
                'The requested category has insufficient remaining capacity for the interval.',
                $allocation->resource_id,
            );
        }
    }

    private function assertExclusiveCategoryAvailable(Allocation $allocation, string $propertyId): void
    {
        if ($allocation->quantity !== 1) {
            throw new AllocationConflictException('An exclusive category can only be reserved once per interval.');
        }

        $propertyResourceIds = Resource::query()->where('property_id', $propertyId)->pluck('id');
        $propertyCategoryIds = ResourceCategory::query()->where('property_id', $propertyId)->pluck('id');
        $block = ResourceBlock::query()
            ->whereIn('resource_id', $propertyResourceIds)
            ->where('starts_at', '<', $allocation->ends_at)
            ->where('ends_at', '>', $allocation->starts_at)
            ->lockForUpdate()
            ->first();
        if ($block !== null) {
            throw new AllocationConflictException(
                'A property block conflicts with the exclusive category request.',
                conflictingId: $block->id,
            );
        }

        $conflict = Allocation::query()
            ->whereKeyNot($allocation->id)
            ->where(function ($query) use ($propertyCategoryIds, $propertyResourceIds): void {
                $query->whereIn('resource_id', $propertyResourceIds)
                    ->orWhere(function ($query) use ($propertyCategoryIds): void {
                        $query->whereNull('resource_id')->whereIn('requested_category_id', $propertyCategoryIds);
                    });
            })
            ->where('starts_at', '<', $allocation->ends_at)
            ->where('ends_at', '>', $allocation->starts_at)
            ->where(fn ($query) => $this->activeAllocationScope($query, $allocation))
            ->lockForUpdate()
            ->first();
        if ($conflict !== null) {
            throw new AllocationConflictException(
                'A property allocation conflicts with the exclusive category request.',
                conflictingId: $conflict->id,
            );
        }
    }

    private function activeAllocationScope($query, Allocation $allocation): void
    {
        $query->where('status', AllocationStatus::Confirmed)
            ->orWhere(function ($query): void {
                $query->where('status', AllocationStatus::Tentative)
                    ->whereHas('reservation', fn ($reservation) => $reservation
                        ->where('status', ReservationStatus::Hold)
                        ->where('hold_expires_at', '>', now()));
            })
            ->orWhere(function ($query) use ($allocation): void {
                $query->where('reservation_id', $allocation->reservation_id)
                    ->where('status', AllocationStatus::Tentative);
            });
    }
}
