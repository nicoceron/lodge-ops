<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MembershipRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreResourceRequest;
use App\Http\Requests\UpdateResourceRequest;
use App\Http\Resources\LodgingResource;
use App\Models\Resource;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ResourceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Resource::class);
        $membership = app(TenantContext::class)->membership();
        $membershipPropertyId = $membership?->property_id;

        $resources = Resource::query()
            ->with('property')
            ->when($membershipPropertyId, fn ($query) => $query->where('property_id', $membershipPropertyId))
            ->when($membership?->role === MembershipRole::Guide, fn ($query) => $query->where('user_id', $request->user()->id))
            ->when($request->query('property_id'), fn ($query, $value) => $query->where('property_id', $value))
            ->when($request->query('type'), fn ($query, $value) => $query->where('type', $value))
            ->when($request->has('active'), fn ($query) => $query->where('is_active', $request->boolean('active')))
            ->orderBy('name')
            ->paginate(min((int) $request->integer('per_page', 50), 100));

        return LodgingResource::collection($resources);
    }

    public function store(StoreResourceRequest $request): LodgingResource
    {
        $this->authorize('create', Resource::class);
        $this->assertMembershipProperty($request->validated('property_id'));

        return new LodgingResource(Resource::query()->create($request->validated())->load('property'));
    }

    public function show(Resource $resource): LodgingResource
    {
        $this->authorize('view', $resource);

        return new LodgingResource($resource->load('property'));
    }

    public function update(UpdateResourceRequest $request, Resource $resource): LodgingResource
    {
        $this->authorize('update', $resource);
        $this->assertMembershipProperty($request->validated('property_id', $resource->property_id));
        $resource->update($request->validated());

        return new LodgingResource($resource->fresh()->load('property'));
    }

    public function destroy(Resource $resource): Response
    {
        $this->authorize('delete', $resource);
        // Historical allocations remain intact; retiring a resource is reversible.
        $resource->update(['is_active' => false]);

        return response()->noContent();
    }

    private function assertMembershipProperty(string $propertyId): void
    {
        $membershipPropertyId = app(TenantContext::class)->membership()?->property_id;
        abort_if($membershipPropertyId !== null && $membershipPropertyId !== $propertyId, 403);
    }
}
