<?php

namespace App\Services;

use App\Enums\AllocationStatus;
use App\Enums\HousekeepingStatus;
use App\Enums\ReservationStatus;
use App\Models\Allocation;
use App\Models\Reservation;
use App\Models\Resource;
use App\Services\Automation\OutboxRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReallocateResource
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly HousekeepingService $housekeeping,
        private readonly ReservationChangeRecorder $changes,
        private readonly OutboxRecorder $outbox,
    ) {}

    public function handle(
        Reservation $reservation,
        Allocation $allocation,
        Resource $resource,
        ?int $actorId,
        ?Allocation $swapWith = null,
        ?string $reason = null,
    ): Reservation {
        return DB::transaction(function () use ($reservation, $allocation, $resource, $actorId, $swapWith, $reason): Reservation {
            $reservationIds = collect([$reservation->id, $swapWith?->reservation_id])->filter()->unique()->sort()->values();
            $lockedReservations = Reservation::query()->whereIn('id', $reservationIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $primary = $lockedReservations->get($reservation->id);
            if (! in_array($primary->status, [ReservationStatus::Hold, ReservationStatus::Confirmed, ReservationStatus::CheckedIn], true)) {
                throw ValidationException::withMessages(['status' => 'Resources may only be reassigned for held, confirmed, or checked-in reservations.']);
            }

            $allocationIds = collect([$allocation->id, $swapWith?->id])->filter()->unique()->sort()->values();
            $lockedAllocations = Allocation::query()->whereIn('id', $allocationIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $current = $lockedAllocations->get($allocation->id);
            if ($current->reservation_id !== $primary->id || $current->status === AllocationStatus::Released) {
                throw ValidationException::withMessages(['allocation_id' => 'The selected allocation is no longer active on this reservation.']);
            }
            $target = Resource::query()->whereKey($resource->id)->lockForUpdate()->firstOrFail();
            $this->assertCompatible($primary, $current, $target);
            $before = $this->changes->snapshot($primary->load('allocations'));

            $other = null;
            $otherReservation = null;
            $otherBefore = null;
            if ($swapWith !== null) {
                $other = $lockedAllocations->get($swapWith->id);
                $otherReservation = $other !== null ? $lockedReservations->get($other->reservation_id) : null;
                if ($other === null || $otherReservation === null
                    || $other->status === AllocationStatus::Released || $other->resource_id !== $target->id) {
                    throw ValidationException::withMessages(['swap_allocation_id' => 'The swap allocation is no longer active on the target resource.']);
                }
                if (! in_array($otherReservation->status, [ReservationStatus::Hold, ReservationStatus::Confirmed, ReservationStatus::CheckedIn], true)) {
                    throw ValidationException::withMessages(['swap_allocation_id' => 'The other reservation cannot be moved in its current state.']);
                }
                if ($current->resource_id === null || $current->starts_at->notEqualTo($other->starts_at) || $current->ends_at->notEqualTo($other->ends_at)) {
                    throw ValidationException::withMessages(['swap_allocation_id' => 'A swap requires matching stay intervals and two assigned resources.']);
                }
                $otherBefore = $this->changes->snapshot($otherReservation->load('allocations'));
            }

            if ($current->resource_id === $target->id && $other === null) {
                return $primary->fresh(['allocations.requestedCategory', 'allocations.resource', 'changes.actor']);
            }

            $oldResourceId = $current->resource_id;
            $current->update(['status' => AllocationStatus::Released]);
            $other?->update(['status' => AllocationStatus::Released]);

            $replacement = $this->replacement($primary, $current, $target->id);
            $this->availability->assertAvailable($replacement);
            $otherReplacement = null;
            if ($swapWith !== null) {
                $otherReplacement = $this->replacement($otherReservation, $other, $oldResourceId);
                $this->availability->assertAvailable($otherReplacement);
            }

            $primary->update(['revision' => $primary->revision + 1]);
            $primary->unsetRelation('allocations');
            $changeType = $other === null ? ($oldResourceId === null ? 'resource_assigned' : 'resource_moved') : 'resource_swapped';
            $change = $this->changes->record($primary, $changeType, [
                'actor_id' => $actorId,
                'before_snapshot' => $before,
                'after_snapshot' => $this->changes->snapshot($primary->fresh('allocations')),
                'metadata' => [
                    'reason' => trim((string) $reason) ?: null,
                    'released_allocation_id' => $current->id,
                    'replacement_allocation_id' => $replacement->id,
                    'from_resource_id' => $oldResourceId,
                    'to_resource_id' => $target->id,
                    'swap_allocation_id' => $other?->id,
                ],
            ]);

            if ($swapWith !== null) {
                $otherReservation->update(['revision' => $otherReservation->revision + 1]);
                $otherReservation->unsetRelation('allocations');
                $this->changes->record($otherReservation, 'resource_swapped', [
                    'actor_id' => $actorId,
                    'before_snapshot' => $otherBefore,
                    'after_snapshot' => $this->changes->snapshot($otherReservation->fresh('allocations')),
                    'metadata' => [
                        'reason' => trim((string) $reason) ?: null,
                        'counterpart_change_id' => $change->id,
                        'released_allocation_id' => $other->id,
                        'replacement_allocation_id' => $otherReplacement->id,
                        'from_resource_id' => $other->resource_id,
                        'to_resource_id' => $oldResourceId,
                    ],
                ]);
                if ($otherReservation->status === ReservationStatus::CheckedIn) {
                    $this->housekeeping->update(Resource::query()->findOrFail($other->resource_id), HousekeepingStatus::Dirty, $actorId);
                }
            }

            if ($primary->status === ReservationStatus::CheckedIn && $oldResourceId !== null) {
                $this->housekeeping->update(Resource::query()->findOrFail($oldResourceId), HousekeepingStatus::Dirty, $actorId);
            }
            $this->outbox->record('reservation', $primary->id, 'reservation.resource_reallocated', [
                'reservation_id' => $primary->id,
                'change_id' => $change->id,
                'from_resource_id' => $oldResourceId,
                'to_resource_id' => $target->id,
                'swap' => $other !== null,
            ]);

            return $primary->fresh(['allocations.requestedCategory', 'allocations.resource', 'changes.actor']);
        }, 3);
    }

    private function replacement(Reservation $reservation, Allocation $source, ?string $resourceId): Allocation
    {
        if ($resourceId === null) {
            throw ValidationException::withMessages(['resource_id' => 'A swap target must have an assigned resource.']);
        }
        $resource = Resource::query()->findOrFail($resourceId);

        return Allocation::query()->create([
            'reservation_id' => $reservation->id,
            'requested_category_id' => $resource->category_id,
            'resource_id' => $resource->id,
            'service_occurrence_id' => $source->service_occurrence_id,
            'status' => $reservation->status === ReservationStatus::Hold ? AllocationStatus::Tentative : AllocationStatus::Confirmed,
            'starts_at' => $source->starts_at,
            'ends_at' => $source->ends_at,
            'quantity' => $source->quantity,
        ]);
    }

    private function assertCompatible(Reservation $reservation, Allocation $allocation, Resource $resource): void
    {
        if ($resource->property_id !== $reservation->property_id || ! $resource->is_active) {
            throw ValidationException::withMessages(['resource_id' => 'The target resource must be active at the reservation property.']);
        }
        if ($allocation->requested_category_id !== null && $allocation->requested_category_id !== $resource->category_id) {
            throw ValidationException::withMessages(['resource_id' => 'Changing resource category requires a priced reservation amendment.']);
        }
    }
}
