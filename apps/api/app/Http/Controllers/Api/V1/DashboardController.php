<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Services\Projections\DashboardProjectionService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __invoke(DashboardProjectionService $projection): JsonResponse
    {
        $this->authorize('viewAny', Reservation::class);

        return response()->json([
            'data' => $projection->build(),
        ]);
    }
}
