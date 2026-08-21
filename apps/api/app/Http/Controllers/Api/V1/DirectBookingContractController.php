<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DirectBookingErrorCode;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Route and response-envelope boundary frozen by P3-07F.
 * P3-07A replaces these fail-closed handlers with domain orchestration.
 */
class DirectBookingContractController extends Controller
{
    public function property(Request $request): JsonResponse
    {
        return $this->unavailable($request);
    }

    public function policy(Request $request): JsonResponse
    {
        return $this->unavailable($request);
    }

    public function availability(Request $request): JsonResponse
    {
        return $this->unavailable($request);
    }

    public function begin(Request $request): JsonResponse
    {
        return $this->unavailable($request);
    }

    public function quote(Request $request): JsonResponse
    {
        return $this->unavailable($request);
    }

    public function hold(Request $request): JsonResponse
    {
        return $this->unavailable($request);
    }

    public function status(Request $request): JsonResponse
    {
        return $this->unavailable($request);
    }

    public function checkout(Request $request): JsonResponse
    {
        return $this->unavailable($request);
    }

    public function retryPayment(Request $request): JsonResponse
    {
        return $this->unavailable($request);
    }

    public function manualEvidence(Request $request): JsonResponse
    {
        return $this->unavailable($request);
    }

    public function recover(Request $request): JsonResponse
    {
        return $this->unavailable($request);
    }

    public function confirmation(Request $request): JsonResponse
    {
        return $this->unavailable($request);
    }

    private function unavailable(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID');
        if (! is_string($correlationId) || ! preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $correlationId)) {
            $correlationId = (string) Str::uuid();
        }

        return response()->json([
            'error' => [
                'code' => DirectBookingErrorCode::BookingUnavailable->value,
                'message' => 'Direct booking is temporarily unavailable.',
                'correlation_id' => $correlationId,
                'retryable' => true,
            ],
        ], 503, [
            'Cache-Control' => 'no-store, private',
            'X-Correlation-ID' => $correlationId,
            'Retry-After' => '60',
        ]);
    }
}
