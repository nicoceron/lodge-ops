<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\IntegrationConnection;
use App\Models\IntegrationDeadLetter;
use App\Models\IntegrationEvent;
use App\Models\IntegrationMapping;
use App\Models\IntegrationSyncRun;
use App\Services\IntegrationConnectionService;
use App\Services\Integrations\EndpointKeyService;
use App\Services\Integrations\IntegrationEventService;
use App\Services\Integrations\IntegrationHealthService;
use App\Services\Integrations\IntegrationReconciliationService;
use App\Services\Integrations\IntegrationRunService;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class IntegrationController extends Controller
{
    public function show(IntegrationConnection $connection, IntegrationHealthService $health): JsonResponse
    {
        $this->authorize('view', $connection);

        return response()->json(['data' => [...$this->connectionData($connection), 'health' => $health->snapshot($connection)]]);
    }

    public function test(Request $request, IntegrationConnection $connection, IntegrationHealthService $health): JsonResponse
    {
        $this->authorize('update', $connection);
        $this->requiredIdempotencyKey($request);
        $data = $request->validate(['capability' => ['required', 'string', 'max:80']]);
        $result = $health->test($connection, $data['capability']);

        return response()->json(['data' => ['healthy' => $result->healthy, 'latency_ms' => $result->latencyMs, 'lag_seconds' => $result->lagSeconds, 'message' => $result->safeMessage]]);
    }

    public function state(Request $request, IntegrationConnection $connection, IntegrationConnectionService $connections, EndpointKeyService $keys): JsonResponse
    {
        $this->authorize('update', $connection);
        $this->requiredIdempotencyKey($request);
        $data = $request->validate([
            'action' => ['required', Rule::in(['enable', 'disable', 'revoke'])],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $updated = match ($data['action']) {
            'enable' => $connections->enable($connection, $request->user()->id, $data['reason']),
            'disable' => $connections->disable($connection, $request->user()->id, $data['reason']),
            'revoke' => tap($connections->revoke($connection, $request->user()->id, $data['reason']), fn () => $keys->revokeAll($connection, $request->user()->id, $data['reason'])),
            default => throw new DomainException('Unsupported integration state transition.'),
        };

        return response()->json(['data' => $this->connectionData($updated)]);
    }

    public function rotateSecret(Request $request, IntegrationConnection $connection, IntegrationConnectionService $connections): JsonResponse
    {
        $this->authorize('update', $connection);
        $this->requiredIdempotencyKey($request);
        $data = $request->validate(['secret_reference' => ['required', 'string', 'max:500'], 'reason' => ['required', 'string', 'min:3', 'max:500']]);
        $updated = $connections->rotateSecretReference($connection, $data['secret_reference'], $request->user()->id, $data['reason']);

        return response()->json(['data' => $this->connectionData($updated)]);
    }

    public function rotateEndpoint(Request $request, IntegrationConnection $connection, EndpointKeyService $keys): JsonResponse
    {
        $this->authorize('update', $connection);
        $idempotencyKey = $this->requiredIdempotencyKey($request);
        $data = $request->validate(['overlap_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'], 'reason' => ['required', 'string', 'min:3', 'max:500']]);

        return response()->json(['data' => $keys->rotate($connection, (int) ($data['overlap_minutes'] ?? 15), $request->user()->id, $data['reason'], $idempotencyKey)]);
    }

    public function runs(Request $request, ?IntegrationConnection $connection = null): JsonResponse
    {
        $this->authorize('viewAny', IntegrationSyncRun::class);
        if ($connection !== null) {
            $this->authorize('view', $connection);
        }
        $query = IntegrationSyncRun::query()->with('items')->latest();
        if ($connection !== null) {
            $query->where('integration_connection_id', $connection->id);
        }
        $this->scopeToMembershipProperty($query);

        return response()->json(['data' => $query->paginate(50)]);
    }

    public function startRun(Request $request, IntegrationConnection $connection, IntegrationRunService $runs): JsonResponse
    {
        $this->authorize('update', $connection);
        $idempotencyKey = $this->requiredIdempotencyKey($request);
        $data = $request->validate([
            'capability' => ['required', Rule::in(IntegrationRunService::CAPABILITIES)],
            'property_id' => ['nullable', 'uuid'],
            'trigger' => ['sometimes', Rule::in(['manual', 'scheduled', 'reconciliation', 'resume'])],
        ]);
        $propertyId = $data['property_id'] ?? $connection->property_id;
        abort_unless($propertyId === null || app(TenantContext::class)->canAccessProperty($propertyId), 403);
        $run = $runs->start($connection, $data['capability'], $propertyId, $data['trigger'] ?? 'manual', $idempotencyKey, $request->user()->id);

        return response()->json(['data' => $run], 202);
    }

    public function events(IntegrationConnection $connection): JsonResponse
    {
        $this->authorize('view', $connection);

        return response()->json(['data' => IntegrationEvent::query()->where('integration_connection_id', $connection->id)->latest('received_at')->paginate(50)]);
    }

    public function replayEvent(Request $request, IntegrationEvent $event, IntegrationEventService $events): JsonResponse
    {
        $this->authorize('update', $event->connection);
        $this->requiredIdempotencyKey($request);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return response()->json(['data' => $events->replay($event, $request->user()->id, $data['reason'])], 202);
    }

    public function deadLetters(IntegrationConnection $connection): JsonResponse
    {
        $this->authorize('view', $connection);

        return response()->json(['data' => IntegrationDeadLetter::query()->where('integration_connection_id', $connection->id)->with(['item', 'event'])->latest()->paginate(50)]);
    }

    public function replayDeadLetter(Request $request, IntegrationDeadLetter $deadLetter, IntegrationRunService $runs, IntegrationEventService $events): JsonResponse
    {
        $this->authorize('update', $deadLetter->connection);
        $this->requiredIdempotencyKey($request);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $result = $deadLetter->item !== null
            ? $runs->replay($deadLetter, $request->user()->id, $data['reason'])
            : $events->replay($deadLetter->event()->firstOrFail(), $request->user()->id, $data['reason']);

        return response()->json(['data' => $result], 202);
    }

    public function mappings(IntegrationConnection $connection): JsonResponse
    {
        $this->authorize('view', $connection);

        return response()->json(['data' => IntegrationMapping::query()->where('integration_connection_id', $connection->id)->latest('valid_from')->paginate(50)]);
    }

    public function reconcile(Request $request, IntegrationConnection $connection, IntegrationReconciliationService $reconciliations): JsonResponse
    {
        $this->authorize('update', $connection);
        $this->requiredIdempotencyKey($request);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return response()->json(['data' => $reconciliations->reconcile($connection, $request->user()->id, $data['reason'])]);
    }

    /** @return array<string,mixed> */
    private function connectionData(IntegrationConnection $connection): array
    {
        return collect($connection->attributesToArray())->except(['secret_reference', 'payment_webhook_key'])->all();
    }

    private function requiredIdempotencyKey(Request $request): string
    {
        return Validator::validate(
            ['key' => $request->header('Idempotency-Key')],
            ['key' => ['required', 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/']],
        )['key'];
    }

    private function scopeToMembershipProperty(Builder $query): void
    {
        $propertyId = app(TenantContext::class)->propertyScopeId();
        if ($propertyId !== null) {
            $query->where('property_id', $propertyId);
        }
    }
}
