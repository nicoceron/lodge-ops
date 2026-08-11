<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OperationalTask;
use App\Services\Projections\OperationsProjectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OperationsProjectionController extends Controller
{
    public function __invoke(Request $request, OperationsProjectionService $projection): JsonResponse
    {
        $this->authorize('viewOperations', OperationalTask::class);

        return response()->json([
            'data' => $projection->build($request->user()),
        ]);
    }
}
