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
use Illuminate\Validation\ValidationException;

class ResourceSuggestionController extends Controller
{
    public function __invoke(Request $request, ResourceSuggestionService $service): JsonResponse
    {
        $this->authorize('suggest', Resource::class);
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

        $context = app(TenantContext::class);
        $propertyId = $data['property_id'] ?? $context->propertyScopeId();
        if ($propertyId !== null) {
            abort_unless($context->canAccessProperty($propertyId), 403);
        }

        $category = isset($data['category_id'])
            ? ResourceCategory::query()->findOrFail($data['category_id'])
            : ResourceCategory::query()
                ->when($propertyId, fn ($query) => $query->where('property_id', $propertyId))
                ->where('slug', $data['category_slug'])
                ->firstOrFail();
        abort_unless($context->canAccessProperty($category->property_id), 403);
        $propertyId ??= $category->property_id;
        if ($category->property_id !== $propertyId) {
            throw ValidationException::withMessages([
                'category_id' => 'The resource category must belong to the selected property.',
            ]);
        }

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
