<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\HousekeepingStatus;
use App\Enums\MembershipRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreResourceRequest;
use App\Http\Requests\UpdateResourceRequest;
use App\Http\Resources\LodgingResource;
use App\Models\Resource;
use App\Services\HousekeepingService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class ResourceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Resource::class);
        $membership = app(TenantContext::class)->membership();
        $membershipPropertyId = app(TenantContext::class)->propertyScopeId();

        $resources = Resource::query()
            ->with(['property', 'category'])
            ->when($membershipPropertyId, fn ($query) => $query->where('property_id', $membershipPropertyId))
            ->when($membership?->role === MembershipRole::Guide, fn ($query) => $query->where('user_id', $request->user()->id))
            ->when($request->query('property_id'), fn ($query, $value) => $query->where('property_id', $value))
            ->when($request->query('category_id'), fn ($query, $value) => $query->where('category_id', $value))
            ->when($request->query('kind'), fn ($query, $value) => $query->whereHas('category', fn ($category) => $category->where('kind', $value)))
            ->when($request->query('category_slug'), fn ($query, $value) => $query->whereHas('category', fn ($category) => $category->where('slug', $value)))
            ->when($request->has('active'), fn ($query) => $query->where('is_active', $request->boolean('active')))
            ->orderBy('name')
            ->paginate(min((int) $request->integer('per_page', 50), 100));

        return LodgingResource::collection($resources);
    }

    public function store(StoreResourceRequest $request): LodgingResource
    {
        $this->authorize('create', Resource::class);
        $this->assertMembershipProperty($request->validated('property_id'));

        return new LodgingResource(Resource::query()->create($request->validated())->load(['property', 'category']));
    }

    public function show(Resource $resource): LodgingResource
    {
        $this->authorize('view', $resource);

        return new LodgingResource($resource->load(['property', 'category']));
    }

    public function update(UpdateResourceRequest $request, Resource $resource): LodgingResource
    {
        $this->authorize('update', $resource);
        $this->assertMembershipProperty($request->validated('property_id', $resource->property_id));
        $resource->update($request->validated());

        return new LodgingResource($resource->fresh()->load(['property', 'category']));
    }

    public function destroy(Resource $resource): Response
    {
        $this->authorize('delete', $resource);
        // Historical allocations remain intact; retiring a resource is reversible.
        $resource->update(['is_active' => false]);

        return response()->noContent();
    }

    public function updateHousekeeping(Request $request, Resource $resource, HousekeepingService $service): LodgingResource
    {
        $this->authorize('updateHousekeeping', $resource);
        $data = $request->validate(['status' => ['required', Rule::enum(HousekeepingStatus::class)]]);

        return new LodgingResource($service->update(
            $resource,
            HousekeepingStatus::from($data['status']),
            $request->user()->id,
        ));
    }

    private function assertMembershipProperty(string $propertyId): void
    {
        abort_unless(app(TenantContext::class)->canAccessProperty($propertyId), 403);
    }
}
