<?php

namespace App\Services;

use App\Enums\AllocationStatus;
use App\Enums\ReservationStatus;
use App\Models\Allocation;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\ResourceCategory;
use App\Models\ServiceOccurrence;
use App\Services\Automation\OutboxRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AllocationWorkflowService
{
    public function __construct(
        private AvailabilityService $availability,
        private ReservationChangeRecorder $changes,
        private OutboxRecorder $outbox,
    ) {}

    public function create(Reservation $reservation, array $data, ?int $actorId = null, ?string $reason = null, bool $requireOperationallyActive = false): Allocation
    {
        return DB::transaction(function () use ($reservation, $data, $actorId, $reason, $requireOperationallyActive): Allocation {
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            if ($requireOperationallyActive) {
                $this->assertOperationallyActive($reservation);
            }
            $this->assertTargetsBelongToReservation($reservation, $data);
            $before = $this->changes->snapshot($reservation->load('allocations'));

            $allocation = new Allocation;
            $allocation->forceFill([
                ...Arr::only($data, ['requested_category_id', 'resource_id', 'service_occurrence_id', 'starts_at', 'ends_at', 'quantity']),
                'quantity' => $data['quantity'] ?? 1,
                'status' => in_array($reservation->status, [ReservationStatus::Confirmed, ReservationStatus::CheckedIn], true)
                    ? AllocationStatus::Confirmed
                    : AllocationStatus::Tentative,
            ]);
            $reservation->allocations()->save($allocation);

            if ($this->reservesCapacity($reservation, $allocation->status)) {
                $this->availability->assertAvailable($allocation);
            }

            $this->recordMutation($reservation, 'resource_assigned', $before, $actorId, $reason, [
                'allocation_id' => $allocation->id,
                'resource_id' => $allocation->resource_id,
                'requested_category_id' => $allocation->requested_category_id,
            ]);

            return $allocation->load(['requestedCategory', 'resource.category', 'serviceOccurrence']);
        }, 3);
    }

    public function update(Reservation $reservation, Allocation $allocation, array $data, ?int $actorId = null, ?string $reason = null, bool $requireOperationallyActive = false): Allocation
    {
        return DB::transaction(function () use ($reservation, $allocation, $data, $actorId, $reason, $requireOperationallyActive): Allocation {
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            if ($requireOperationallyActive) {
                $this->assertOperationallyActive($reservation);
            }
            $allocation = Allocation::query()->where('reservation_id', $reservation->id)
                ->lockForUpdate()->findOrFail($allocation->id);
            $before = $this->changes->snapshot($reservation->load('allocations'));

            $candidate = [...$allocation->only([
                'requested_category_id', 'resource_id', 'service_occurrence_id', 'starts_at', 'ends_at', 'quantity', 'status',
            ]), ...$data];
            $this->assertTargetsBelongToReservation($reservation, $candidate);

            $requestedStatus = isset($data['status']) ? AllocationStatus::from($data['status']) : $allocation->status;
            if ($requestedStatus !== AllocationStatus::Released) {
                $requestedStatus = $reservation->status === ReservationStatus::Confirmed
                    ? AllocationStatus::Confirmed
                    : AllocationStatus::Tentative;
            }
            $candidate['status'] = $requestedStatus;
            $allocation->fill($candidate);

            if ($this->reservesCapacity($reservation, $requestedStatus)) {
                $this->availability->assertAvailable($allocation);
            }
            $allocation->save();

            $this->recordMutation($reservation, 'resource_assignment_updated', $before, $actorId, $reason, [
                'allocation_id' => $allocation->id,
                'resource_id' => $allocation->resource_id,
                'status' => $allocation->status->value,
            ]);

            return $allocation->load(['requestedCategory', 'resource.category', 'serviceOccurrence']);
        }, 3);
    }

    public function release(Reservation $reservation, Allocation $allocation, ?int $actorId = null, ?string $reason = null, bool $requireOperationallyActive = false): void
    {
        DB::transaction(function () use ($reservation, $allocation, $actorId, $reason, $requireOperationallyActive): void {
            $reservation = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if ($requireOperationallyActive) {
                $this->assertOperationallyActive($reservation);
            }
            $before = $this->changes->snapshot($reservation->load('allocations'));
            $locked = Allocation::query()->where('reservation_id', $reservation->id)->whereKey($allocation->id)
                ->lockForUpdate()->firstOrFail();
            if ($locked->status === AllocationStatus::Released) {
                throw ValidationException::withMessages(['allocation_id' => 'The selected allocation is already released.']);
            }
            $locked->update(['status' => AllocationStatus::Released]);
            $this->recordMutation($reservation, 'resource_released', $before, $actorId, $reason, [
                'allocation_id' => $locked->id,
                'resource_id' => $locked->resource_id,
            ]);
        }, 3);
    }

    public function assertOperationallyActive(Reservation $reservation): void
    {
        $active = in_array($reservation->status, [ReservationStatus::Confirmed, ReservationStatus::CheckedIn], true)
            || ($reservation->status === ReservationStatus::Hold && $this->holdIsActive($reservation));
        if (! $active) {
            throw ValidationException::withMessages(['status' => 'Shared resources may only change on an active held, confirmed, or checked-in reservation.']);
        }
    }

    private function assertTargetsBelongToReservation(Reservation $reservation, array $data): void
    {
        if (empty($data['requested_category_id']) && empty($data['resource_id']) && empty($data['service_occurrence_id'])) {
            throw ValidationException::withMessages([
                'requested_category_id' => 'An allocation must request a category, assign a resource, or target a service occurrence.',
            ]);
        }

        $category = null;
        if (! empty($data['requested_category_id'])) {
            $category = ResourceCategory::query()->whereKey($data['requested_category_id'])->where('is_active', true)->first();
            if ($category === null || $category->property_id !== $reservation->property_id) {
                throw ValidationException::withMessages([
                    'requested_category_id' => 'The requested category must be active and belong to the reservation property.',
                ]);
            }
        }

        if (! empty($data['resource_id'])) {
            $resource = Resource::query()->whereKey($data['resource_id'])->where('is_active', true)->first();
            if ($resource === null || $resource->property_id !== $reservation->property_id) {
                throw ValidationException::withMessages([
                    'resource_id' => 'The resource must be active and belong to the reservation property.',
                ]);
            }
            if ($category !== null && $resource->category_id !== $category->id) {
                throw ValidationException::withMessages([
                    'resource_id' => 'The assigned resource must belong to the requested category.',
                ]);
            }
            $data['requested_category_id'] = $resource->category_id;
        }

        if (! empty($data['service_occurrence_id'])) {
            $occurrence = ServiceOccurrence::query()->whereKey($data['service_occurrence_id'])->first();
            if ($occurrence === null || $occurrence->property_id !== $reservation->property_id || $occurrence->is_cancelled) {
                throw ValidationException::withMessages([
                    'service_occurrence_id' => 'The occurrence must be active and belong to the reservation property.',
                ]);
            }
        }
    }

    private function reservesCapacity(Reservation $reservation, AllocationStatus $status): bool
    {
        return $status !== AllocationStatus::Released && (
            $reservation->status === ReservationStatus::Confirmed
            || ($reservation->status === ReservationStatus::Hold && $this->holdIsActive($reservation))
        );
    }

    private function holdIsActive(Reservation $reservation): bool
    {
        return $reservation->hold_expires_at !== null
            && CarbonImmutable::parse($reservation->hold_expires_at)->isFuture();
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $metadata */
    private function recordMutation(Reservation $reservation, string $type, array $before, ?int $actorId, ?string $reason, array $metadata): void
    {
        $reservation->forceFill(['revision' => $reservation->revision + 1])->save();
        $reservation->unsetRelation('allocations');
        $change = $this->changes->record($reservation, $type, [
            'actor_id' => $actorId,
            'before_snapshot' => $before,
            'after_snapshot' => $this->changes->snapshot($reservation->fresh('allocations')),
            'metadata' => [...$metadata, 'reason' => trim((string) $reason) ?: null],
        ]);
        $this->outbox->record('reservation', $reservation->id, 'reservation.'.$type, [
            'reservation_id' => $reservation->id,
            'change_id' => $change->id,
            ...$metadata,
        ]);
    }
}
