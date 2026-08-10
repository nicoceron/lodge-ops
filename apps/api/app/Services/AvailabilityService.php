<?php

namespace App\Services;

use App\Enums\AllocationStatus;
use App\Exceptions\AllocationConflictException;
use App\Models\Allocation;
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
            // Locking the resource serializes confirmations for this resource.
            Resource::query()->lockForUpdate()->findOrFail($allocation->resource_id);

            $block = ResourceBlock::query()
                ->where('resource_id', $allocation->resource_id)
                ->where('starts_at', '<', $allocation->ends_at)
                ->where('ends_at', '>', $allocation->starts_at)
                ->lockForUpdate()
                ->first();

            if ($block !== null) {
                throw new AllocationConflictException('The resource is blocked for the requested interval.', $allocation->resource_id, $block->id);
            }

            $conflict = Allocation::query()
                ->where('resource_id', $allocation->resource_id)
                ->whereKeyNot($allocation->id)
                ->where(function ($query) use ($allocation): void {
                    $query->where('status', AllocationStatus::Confirmed)
                        ->orWhere(function ($query) use ($allocation): void {
                            $query->where('reservation_id', $allocation->reservation_id)
                                ->where('status', AllocationStatus::Tentative);
                        });
                })
                ->where('starts_at', '<', $allocation->ends_at)
                ->where('ends_at', '>', $allocation->starts_at)
                ->lockForUpdate()
                ->first();

            if ($conflict !== null) {
                throw new AllocationConflictException('The resource is already allocated for the requested interval.', $allocation->resource_id, $conflict->id);
            }
        }

        if ($allocation->service_occurrence_id !== null) {
            $occurrence = ServiceOccurrence::query()->lockForUpdate()->findOrFail($allocation->service_occurrence_id);
            $allocated = Allocation::query()
                ->where('service_occurrence_id', $occurrence->id)
                ->whereKeyNot($allocation->id)
                ->where(function ($query) use ($allocation): void {
                    $query->where('status', AllocationStatus::Confirmed)
                        ->orWhere('reservation_id', $allocation->reservation_id);
                })
                ->sum('quantity');

            if ($occurrence->is_cancelled || $allocated + $allocation->quantity > $occurrence->capacity) {
                throw new AllocationConflictException('The service occurrence has insufficient capacity.', conflictingId: $occurrence->id);
            }
        }
    }
}
