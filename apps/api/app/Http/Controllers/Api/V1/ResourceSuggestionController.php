<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Resource;
use App\Models\ResourceCategory;
use App\Services\ResourceSuggestionService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResourceSuggestionController extends Controller
{
    public function __invoke(Request $request, ResourceSuggestionService $service): JsonResponse
    {
        $this->authorize('viewAny', Resource::class);
        $data = $request->validate([
            'category_id' => ['required_without:category_slug', 'nullable', 'uuid'],
            'category_slug' => ['required_without:category_id', 'nullable', 'string', 'max:40'],
            'property_id' => ['nullable', 'uuid'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'quantity' => ['sometimes', 'integer', 'min:1'],
            'capabilities' => ['sometimes', 'array'],
            'capabilities.*' => ['string', 'max:80'],
            'languages' => ['sometimes', 'array'],
            'languages.*' => ['string', 'max:40'],
        ]);

        $propertyId = $data['property_id'] ?? app(TenantContext::class)->membership()?->property_id;
        $category = isset($data['category_id'])
            ? ResourceCategory::query()->findOrFail($data['category_id'])
            : ResourceCategory::query()
                ->when($propertyId, fn ($query) => $query->where('property_id', $propertyId))
                ->where('slug', $data['category_slug'])
                ->firstOrFail();

        $suggestions = $service->suggest(
            $category,
            CarbonImmutable::parse($data['starts_at'])->utc(),
            CarbonImmutable::parse($data['ends_at'])->utc(),
            $data['quantity'] ?? 1,
            $data['capabilities'] ?? [],
            $data['languages'] ?? [],
            $propertyId,
        );

        return response()->json(['data' => $suggestions]);
    }
}
