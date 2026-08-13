<?php

namespace App\Services;

use App\Enums\AllocationStatus;
use App\Enums\ReservationStatus;
use App\Models\Allocation;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\ResourceCategory;
use App\Models\ServiceOccurrence;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AllocationWorkflowService
{
    public function __construct(private AvailabilityService $availability) {}

    public function create(Reservation $reservation, array $data): Allocation
    {
        return DB::transaction(function () use ($reservation, $data): Allocation {
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            $this->assertTargetsBelongToReservation($reservation, $data);

            $allocation = $reservation->allocations()->create([
                ...Arr::only($data, ['requested_category_id', 'resource_id', 'service_occurrence_id', 'starts_at', 'ends_at', 'quantity']),
                'quantity' => $data['quantity'] ?? 1,
                'status' => $reservation->status === ReservationStatus::Confirmed
                    ? AllocationStatus::Confirmed
                    : AllocationStatus::Tentative,
            ]);

            if ($this->reservesCapacity($reservation, $allocation->status)) {
                $this->availability->assertAvailable($allocation);
            }

            return $allocation->load(['requestedCategory', 'resource.category', 'serviceOccurrence']);
        }, 3);
    }

    public function update(Reservation $reservation, Allocation $allocation, array $data): Allocation
    {
        return DB::transaction(function () use ($reservation, $allocation, $data): Allocation {
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            $allocation = Allocation::query()->where('reservation_id', $reservation->id)
                ->lockForUpdate()->findOrFail($allocation->id);

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

            return $allocation->load(['requestedCategory', 'resource.category', 'serviceOccurrence']);
        }, 3);
    }

    public function release(Reservation $reservation, Allocation $allocation): void
    {
        DB::transaction(function () use ($reservation, $allocation): void {
            Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            Allocation::query()->where('reservation_id', $reservation->id)->whereKey($allocation->id)
                ->lockForUpdate()->firstOrFail()->update(['status' => AllocationStatus::Released]);
        }, 3);
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
            || ($reservation->status === ReservationStatus::Hold && $reservation->hold_expires_at?->isFuture())
        );
    }
}
