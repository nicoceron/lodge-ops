<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Services\BookingQuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingQuoteController extends Controller
{
    public function store(Request $request, BookingQuoteService $quotes): JsonResponse
    {
        $this->authorize('create', Reservation::class);
        $data = $request->validate([
            'property_id' => ['required', 'uuid'],
            'rate_plan_id' => ['required', 'uuid'],
            'resource_category_id' => ['required', 'uuid'],
            'resource_id' => ['nullable', 'uuid'],
            'program_id' => ['nullable', 'uuid'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'adults' => ['required', 'integer', 'min:1', 'max:1000'],
            'children' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);
        $quote = $quotes->create($data)->load('lines');

        return response()->json(['data' => [
            'id' => $quote->id,
            'property_id' => $quote->property_id,
            'currency' => $quote->currency,
            'subtotal_minor' => $quote->subtotal_minor,
            'tax_minor' => $quote->tax_minor,
            'total_minor' => $quote->total_minor,
            'deposit_policy_snapshot' => $quote->deposit_policy_snapshot,
            'cancellation_policy_snapshot' => $quote->cancellation_policy_snapshot,
            'expires_at' => $quote->expires_at,
            'lines' => $quote->lines,
        ]], 201);
    }
}
