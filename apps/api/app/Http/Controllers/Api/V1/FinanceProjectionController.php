<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Projections\FinanceProjectionService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceProjectionController extends Controller
{
    public function __invoke(
        Request $request,
        TenantContext $context,
        FinanceProjectionService $projection,
    ): JsonResponse {
        $this->authorize('viewFinance', Payment::class);
        $timezone = $context->tenant()->timezone;
        $now = CarbonImmutable::now($timezone);
        $validated = $request->validate([
            'start' => ['nullable', 'date'],
            'end' => ['nullable', 'date', 'after_or_equal:start'],
            'display_currency' => ['nullable', 'string', 'size:3'],
        ]);
        $start = filled($validated['start'] ?? null)
            ? CarbonImmutable::parse((string) $validated['start'], $timezone)->startOfDay()->utc()
            : $now->startOfMonth()->utc();
        $end = filled($validated['end'] ?? null)
            ? CarbonImmutable::parse((string) $validated['end'], $timezone)->addDay()->startOfDay()->utc()
            : $now->addMonth()->startOfMonth()->utc();

        return response()->json([
            'data' => $projection->build(
                $start,
                $end,
                (string) ($validated['display_currency'] ?? $context->tenant()->currency),
            ),
        ]);
    }
}
