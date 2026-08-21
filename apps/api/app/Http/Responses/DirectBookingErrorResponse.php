<?php

namespace App\Http\Responses;

use App\Enums\DirectBookingErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class DirectBookingErrorResponse
{
    /** @param array<string, list<string>>|null $fields @param array<string, string|int> $headers */
    public static function make(
        Request $request,
        DirectBookingErrorCode $code,
        ?array $fields = null,
        array $headers = [],
    ): JsonResponse {
        $correlation = $request->attributes->get('direct_booking_correlation_id') ?? $request->header('X-Correlation-ID');
        if (! is_string($correlation) || preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $correlation) !== 1) {
            $correlation = (string) Str::uuid();
        }
        $request->attributes->set('direct_booking_correlation_id', $correlation);

        return response()->json(['error' => array_filter([
            'code' => $code->value,
            'message' => $code->publicMessage(),
            'correlation_id' => $correlation,
            'retryable' => $code->retryable(),
            'fields' => $fields,
        ], static fn (mixed $value): bool => $value !== null)], $code->httpStatus(), [
            'Cache-Control' => 'no-store, private',
            'X-Correlation-ID' => $correlation,
            ...$headers,
        ]);
    }
}
