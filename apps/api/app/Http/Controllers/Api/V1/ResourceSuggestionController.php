<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ResourceType;
use App\Http\Controllers\Controller;
use App\Models\Resource;
use App\Services\ResourceSuggestionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ResourceSuggestionController extends Controller
{
    public function __invoke(Request $request, ResourceSuggestionService $service): JsonResponse
    {
        $this->authorize('viewAny', Resource::class);
        $data = $request->validate([
            'type' => ['required', Rule::enum(ResourceType::class)],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'quantity' => ['sometimes', 'integer', 'min:1'],
            'capabilities' => ['sometimes', 'array'],
            'capabilities.*' => ['string', 'max:80'],
            'languages' => ['sometimes', 'array'],
            'languages.*' => ['string', 'max:40'],
        ]);

        $suggestions = $service->suggest(
            ResourceType::from($data['type']),
            CarbonImmutable::parse($data['starts_at'])->utc(),
            CarbonImmutable::parse($data['ends_at'])->utc(),
            $data['quantity'] ?? 1,
            $data['capabilities'] ?? [],
            $data['languages'] ?? [],
        );

        return response()->json(['data' => $suggestions]);
    }
}
