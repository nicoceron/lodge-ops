<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AllocationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreServiceOccurrenceRequest;
use App\Http\Requests\UpdateServiceOccurrenceRequest;
use App\Http\Resources\ServiceOccurrenceResource;
use App\Models\Program;
use App\Models\ServiceOccurrence;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServiceOccurrenceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ServiceOccurrence::class);
        $membershipPropertyId = app(TenantContext::class)->membership()?->property_id;

        return ServiceOccurrenceResource::collection(ServiceOccurrence::query()
            ->with('program')
            ->withSum(['allocations as allocated_quantity' => fn ($query) => $query->where('status', '!=', AllocationStatus::Released)], 'quantity')
            ->when($membershipPropertyId, fn ($query) => $query->where('property_id', $membershipPropertyId))
            ->when($request->query('property_id'), fn ($query, $id) => $query->where('property_id', $id))
            ->when($request->query('program_id'), fn ($query, $id) => $query->where('program_id', $id))
            ->when($request->query('from'), fn ($query, $from) => $query->where('ends_at', '>', $from))
            ->when($request->query('to'), fn ($query, $to) => $query->where('starts_at', '<', $to))
            ->when($request->has('cancelled'), fn ($query) => $query->where('is_cancelled', $request->boolean('cancelled')))
            ->orderBy('starts_at')
            ->paginate(min(max(1, $request->integer('per_page', 50)), 100)));
    }

    public function store(StoreServiceOccurrenceRequest $request): ServiceOccurrenceResource
    {
        $this->authorize('create', ServiceOccurrence::class);
        $data = $request->validated();
        $this->assertMembershipProperty($data['property_id']);
        $this->assertProgramProperty($data['program_id'], $data['property_id']);

        return new ServiceOccurrenceResource(ServiceOccurrence::query()->create($data)->load('program'));
    }

    public function show(ServiceOccurrence $serviceOccurrence): ServiceOccurrenceResource
    {
        $this->authorize('view', $serviceOccurrence);

        return new ServiceOccurrenceResource($serviceOccurrence->load('program'));
    }

    public function update(UpdateServiceOccurrenceRequest $request, ServiceOccurrence $serviceOccurrence): ServiceOccurrenceResource
    {
        $this->authorize('update', $serviceOccurrence);
        $data = $request->validated();
        $this->assertMembershipProperty($data['property_id'] ?? $serviceOccurrence->property_id);
        $this->assertProgramProperty(
            $data['program_id'] ?? $serviceOccurrence->program_id,
            $data['property_id'] ?? $serviceOccurrence->property_id,
        );
        $serviceOccurrence->update($data);

        return new ServiceOccurrenceResource($serviceOccurrence->fresh()->load('program'));
    }

    public function destroy(ServiceOccurrence $serviceOccurrence): Response
    {
        $this->authorize('delete', $serviceOccurrence);
        DB::transaction(function () use ($serviceOccurrence): void {
            $locked = ServiceOccurrence::query()->lockForUpdate()->findOrFail($serviceOccurrence->id);
            $locked->update(['is_cancelled' => true]);
            $locked->allocations()->where('status', AllocationStatus::Tentative)->update(['status' => AllocationStatus::Released]);
        }, 3);

        return response()->noContent();
    }

    private function assertProgramProperty(string $programId, string $propertyId): void
    {
        if (! Program::query()->whereKey($programId)->where('property_id', $propertyId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['program_id' => 'The program must be active and belong to the selected property.']);
        }
    }

    private function assertMembershipProperty(string $propertyId): void
    {
        $membershipPropertyId = app(TenantContext::class)->membership()?->property_id;
        if ($membershipPropertyId !== null && $membershipPropertyId !== $propertyId) {
            throw ValidationException::withMessages(['property_id' => 'The property is outside your active membership scope.']);
        }
    }
}
