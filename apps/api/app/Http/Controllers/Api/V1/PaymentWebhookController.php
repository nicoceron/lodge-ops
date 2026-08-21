<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Payments\ReceiveProviderWebhook;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, string $webhookKey, ReceiveProviderWebhook $receiver): JsonResponse
    {
        $headers = collect($request->headers->all())->mapWithKeys(fn (array $values, string $key): array => [strtolower($key) => (string) ($values[0] ?? '')])->all();
        try {
            $event = $receiver->handle($webhookKey, $request->getContent(), $headers, $request->query->all());
        } catch (ModelNotFoundException|NotFoundHttpException) {
            abort(404, 'Unknown provider notification endpoint.');
        } catch (RuntimeException) {
            abort(401, 'Invalid provider notification.');
        }

        return response()->json(['accepted' => true, 'event_id' => $event->id]);
    }
}
