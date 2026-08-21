<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAllocationRequest;
use App\Http\Requests\UpdateAllocationRequest;
use App\Http\Resources\AllocationResource;
use App\Models\Allocation;
use App\Models\Reservation;
use App\Services\AllocationWorkflowService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Response;

class AllocationController extends Controller
{
    public function store(
        StoreAllocationRequest $request,
        Reservation $reservation,
        AllocationWorkflowService $service,
    ): AllocationResource {
        $this->authorize('create', Allocation::class);
        $this->authorize('view', $reservation);
        $this->assertMembershipProperty($reservation);

        return new AllocationResource($service->create($reservation, $request->validated(), $request->user()?->id));
    }

    public function update(
        UpdateAllocationRequest $request,
        Reservation $reservation,
        Allocation $allocation,
        AllocationWorkflowService $service,
    ): AllocationResource {
        abort_unless($allocation->reservation_id === $reservation->id, 404);
        $this->authorize('update', $allocation);
        $this->assertMembershipProperty($reservation);

        return new AllocationResource($service->update($reservation, $allocation, $request->validated(), $request->user()?->id));
    }

    public function destroy(
        Reservation $reservation,
        Allocation $allocation,
        AllocationWorkflowService $service,
    ): Response {
        abort_unless($allocation->reservation_id === $reservation->id, 404);
        $this->authorize('delete', $allocation);
        $this->assertMembershipProperty($reservation);
        $service->release($reservation, $allocation, auth()->id(), 'Allocation released through the API.');

        return response()->noContent();
    }

    private function assertMembershipProperty(Reservation $reservation): void
    {
        $propertyId = app(TenantContext::class)->membership()?->property_id;
        abort_if($propertyId !== null && $propertyId !== $reservation->property_id, 403);
    }
}
