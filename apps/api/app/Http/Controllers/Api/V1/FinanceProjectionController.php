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
        $start = $request->filled('start')
            ? CarbonImmutable::parse((string) $request->input('start'), $timezone)->startOfDay()->utc()
            : $now->startOfMonth()->utc();
        $end = $request->filled('end')
            ? CarbonImmutable::parse((string) $request->input('end'), $timezone)->addDay()->startOfDay()->utc()
            : $now->addMonth()->startOfMonth()->utc();

        return response()->json([
            'data' => $projection->build(
                $start,
                $end,
                (string) $request->input('display_currency', $context->tenant()->currency),
            ),
        ]);
    }
}
