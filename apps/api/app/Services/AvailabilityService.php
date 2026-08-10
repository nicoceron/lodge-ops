<?php

namespace App\Services;

use App\Enums\AllocationStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\AllocationConflictException;
use App\Models\Allocation;
use App\Models\Property;
use App\Models\Resource;
use App\Models\ResourceBlock;
use App\Models\ServiceOccurrence;

class AvailabilityService
{
    public function assertAvailable(Allocation $allocation): void
    {
        if ($allocation->starts_at >= $allocation->ends_at) {
            throw new AllocationConflictException('Allocation end must be after its start.', $allocation->resource_id);
        }

        if ($allocation->resource_id !== null) {
            $resource = Resource::query()->lockForUpdate()->findOrFail($allocation->resource_id);
            // A property lock serializes ordinary and buyout allocations, which otherwise lock
            // different resource rows and could both pass their conflict checks concurrently.
            Property::query()->whereKey($resource->property_id)->lockForUpdate()->firstOrFail();

            $propertyResourceIds = Resource::query()->where('property_id', $resource->property_id)->pluck('id');
            $buyoutResourceIds = Resource::query()
                ->where('property_id', $resource->property_id)
                ->get()
                ->filter(fn (Resource $candidate): bool => $candidate->isBuyout())
                ->pluck('id');
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
                ->whereIn('resource_id', $conflictingResourceIds)
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
            if ($allocatedQuantity + $allocation->quantity > $resource->capacity) {
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
    }
}
