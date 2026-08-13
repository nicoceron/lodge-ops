<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MembershipRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreResourceBlockRequest;
use App\Http\Requests\UpdateResourceBlockRequest;
use App\Http\Resources\ResourceBlockResource;
use App\Models\Resource;
use App\Models\ResourceBlock;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class ResourceBlockController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ResourceBlock::class);
        $membership = app(TenantContext::class)->membership();

        return ResourceBlockResource::collection(ResourceBlock::query()
            ->with('resource.category')
            ->when($membership?->property_id, fn ($query, $id) => $query->whereHas('resource', fn ($resource) => $resource->where('property_id', $id)))
            ->when($membership?->role === MembershipRole::Guide, fn ($query) => $query->whereHas('resource', fn ($resource) => $resource->where('user_id', auth()->id())))
            ->when($request->query('resource_id'), fn ($query, $id) => $query->where('resource_id', $id))
            ->when($request->query('from'), fn ($query, $from) => $query->where('ends_at', '>', $from))
            ->when($request->query('to'), fn ($query, $to) => $query->where('starts_at', '<', $to))
            ->orderBy('starts_at')
            ->paginate(min(max(1, $request->integer('per_page', 50)), 100)));
    }

    public function store(StoreResourceBlockRequest $request): ResourceBlockResource
    {
        $this->authorize('create', ResourceBlock::class);
        $data = $request->validated();
        $this->assertCanManageResource($data['resource_id']);

        return new ResourceBlockResource(ResourceBlock::query()->create($data)->load('resource.category'));
    }

    public function show(ResourceBlock $resourceBlock): ResourceBlockResource
    {
        $this->authorize('view', $resourceBlock);

        return new ResourceBlockResource($resourceBlock->load('resource.category'));
    }

    public function update(UpdateResourceBlockRequest $request, ResourceBlock $resourceBlock): ResourceBlockResource
    {
        $this->authorize('update', $resourceBlock);
        $data = $request->validated();
        $this->assertCanManageResource($data['resource_id'] ?? $resourceBlock->resource_id);
        $resourceBlock->update($data);

        return new ResourceBlockResource($resourceBlock->fresh()->load('resource.category'));
    }

    public function destroy(ResourceBlock $resourceBlock): Response
    {
        $this->authorize('delete', $resourceBlock);
        $resourceBlock->delete();

        return response()->noContent();
    }

    private function assertCanManageResource(string $resourceId): void
    {
        $resource = Resource::query()->findOrFail($resourceId);
        $membership = app(TenantContext::class)->membership();
        if ($membership?->property_id && $membership->property_id !== $resource->property_id) {
            throw ValidationException::withMessages(['resource_id' => 'The resource is outside your assigned property.']);
        }
        if ($membership?->role === MembershipRole::Guide && $resource->user_id !== auth()->id()) {
            throw ValidationException::withMessages(['resource_id' => 'Guides may only manage their own availability.']);
        }
    }
}
