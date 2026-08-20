<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Communications\ReceiveCommunicationWebhook;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommunicationWebhookController extends Controller
{
    public function __invoke(Request $request, string $endpointKey, ReceiveCommunicationWebhook $receiver): JsonResponse
    {
        $headers = collect($request->headers->all())
            ->mapWithKeys(fn (array $values, string $key): array => [strtolower($key) => (string) ($values[0] ?? '')])->all();

        try {
            $receiver->handle($endpointKey, $request->getContent(), $headers);
        } catch (DomainException|ModelNotFoundException) {
            return response()->json(['message' => 'Invalid provider notification.'], 401);
        }

        return response()->json(['accepted' => true], 202);
    }
}
