<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PropertyResource;
use App\Models\Property;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PropertyController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Property::class);
        $propertyId = app(TenantContext::class)->membership()?->property_id;

        return PropertyResource::collection(Property::query()
            ->when($propertyId, fn ($query) => $query->whereKey($propertyId))
            ->when($request->has('active'), fn ($query) => $query->where('is_active', $request->boolean('active')))
            ->orderBy('name')
            ->paginate(min(max(1, $request->integer('per_page', 50)), 100)));
    }
}
