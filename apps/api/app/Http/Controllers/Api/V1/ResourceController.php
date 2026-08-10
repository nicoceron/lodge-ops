<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreResourceRequest;
use App\Http\Requests\UpdateResourceRequest;
use App\Http\Resources\LodgingResource;
use App\Models\Resource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ResourceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Resource::class);

        $resources = Resource::query()
            ->with('property')
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
}
