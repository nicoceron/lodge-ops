<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Integrations\IntegrationEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class IntegrationWebhookController extends Controller
{
    public function __invoke(Request $request, string $endpointKey, IntegrationEventService $events): JsonResponse
    {
        $headers = collect($request->headers->all())->mapWithKeys(
            fn (array $values, string $key): array => [strtolower($key) => (string) ($values[0] ?? '')],
        )->all();
        try {
            $event = $events->receive($endpointKey, $request->getContent(), $headers);
        } catch (Throwable) {
            abort(401, 'Invalid integration webhook.');
        }

        return response()->json(['accepted' => true, 'event_id' => $event->id], 202);
    }
}
