<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Payments\InPersonPaymentGatewayFactory;
use App\Data\Payments\ProviderTerminalQuery;
use App\Enums\PaymentChannel;
use App\Enums\PaymentRequestPurpose;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentAttemptResource;
use App\Http\Resources\PaymentTerminalResource;
use App\Http\Resources\ProviderPosLocationResource;
use App\Models\IntegrationConnection;
use App\Models\PaymentAttempt;
use App\Models\PaymentRequest;
use App\Models\PaymentTerminal;
use App\Models\Property;
use App\Models\ProviderPosLocation;
use App\Models\Reservation;
use App\Models\ReservationChange;
use App\Services\Payments\CancelInPersonOrder;
use App\Services\Payments\ExecuteInPersonRefund;
use App\Services\Payments\InitiateInPersonPayment;
use App\Services\Payments\ReconcileInPersonOrder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InPersonPaymentController extends Controller
{
    public function terminals(Request $request)
    {
        $this->authorize('viewAny', PaymentTerminal::class);
        $query = PaymentTerminal::query()->orderBy('display_name');
        if ($request->filled('property_id')) {
            abort_unless(app(TenantContext::class)->canAccessProperty((string) $request->input('property_id')), 403);
            $query->where('property_id', $request->input('property_id'));
        }

        return PaymentTerminalResource::collection($query->get());
    }

    public function posLocations(Request $request)
    {
        $this->authorize('viewAny', ProviderPosLocation::class);
        $query = ProviderPosLocation::query()->orderBy('display_name');
        if ($request->filled('property_id')) {
            abort_unless(app(TenantContext::class)->canAccessProperty((string) $request->input('property_id')), 403);
            $query->where('property_id', $request->input('property_id'));
        }

        return ProviderPosLocationResource::collection($query->get());
    }

    public function syncTerminals(Request $request, InPersonPaymentGatewayFactory $gateways): JsonResponse
    {
        $this->authorize('create', PaymentTerminal::class);
        $data = $request->validate([
            'integration_connection_id' => ['required', 'uuid'],
            'property_id' => ['required', 'uuid'],
            'store_id' => ['nullable', 'string', 'max:160'],
        ]);
        $connection = IntegrationConnection::query()->findOrFail($data['integration_connection_id']);
        $property = Property::query()->findOrFail($data['property_id']);
        abort_unless(app(TenantContext::class)->canAccessProperty($property->id), 403);
        abort_unless(($connection->property_id === null || $connection->property_id === $property->id)
            && $connection->provider === 'mercado_pago' && in_array($connection->product, ['checkout_pro', 'orders'], true)
            && $connection->is_enabled && $connection->revoked_at === null && $connection->secret_reference !== null
            && $connection->connectionCapabilities()->where('capability', 'payment.point_orders')
                ->where('direction', 'outbound')->where('state', 'enabled')->exists(), 422);
        $terminals = $gateways->for($connection)->listTerminals(new ProviderTerminalQuery($data['store_id'] ?? null));
        $rows = collect($terminals)->map(function ($terminal) use ($connection, $property): PaymentTerminal {
            $identity = [
                'provider' => 'mercado_pago',
                'environment' => $connection->environment,
                'provider_account' => $connection->external_account_id,
                'provider_terminal_id' => $terminal->id,
            ];
            $existing = PaymentTerminal::query()->where($identity)->first();
            abort_if($existing !== null && ($existing->property_id !== $property->id
                || $existing->integration_connection_id !== $connection->id), 422,
                'This provider terminal is already bound to another property or connection.');

            return PaymentTerminal::query()->updateOrCreate($identity, [
                'property_id' => $property->id,
                'integration_connection_id' => $connection->id,
                'provider_store_id' => $terminal->storeId,
                'display_name' => 'Point '.substr($terminal->id, -12),
                'operating_mode' => $terminal->operatingMode,
                'is_enabled' => strtoupper($terminal->operatingMode) === 'PDV',
                'health_state' => 'synced',
                'last_synced_at' => now(),
                'last_error' => null,
            ]);
        });

        return response()->json(['data' => PaymentTerminalResource::collection($rows)->resolve($request)]);
    }

    public function registerPos(Request $request): JsonResponse
    {
        $this->authorize('create', ProviderPosLocation::class);
        $data = $request->validate([
            'integration_connection_id' => ['required', 'uuid'],
            'property_id' => ['required', 'uuid'],
            'provider_store_id' => ['required', 'string', 'max:160'],
            'external_pos_id' => ['required', 'string', 'max:160', 'regex:/^[A-Za-z0-9_-]+$/'],
            'display_name' => ['required', 'string', 'max:120'],
            'qr_mode' => ['required', 'in:static,dynamic,hybrid'],
        ]);
        $connection = IntegrationConnection::query()->findOrFail($data['integration_connection_id']);
        $property = Property::query()->findOrFail($data['property_id']);
        abort_unless(app(TenantContext::class)->canAccessProperty($property->id), 403);
        abort_unless(($connection->property_id === null || $connection->property_id === $property->id)
            && $connection->provider === 'mercado_pago' && in_array($connection->product, ['checkout_pro', 'orders'], true)
            && $connection->is_enabled && $connection->revoked_at === null && $connection->secret_reference !== null
            && $connection->connectionCapabilities()->where('capability', 'payment.qr_orders')
                ->where('direction', 'outbound')->where('state', 'enabled')->exists(), 422);
        $identity = [
            'provider' => 'mercado_pago',
            'environment' => $connection->environment,
            'provider_account' => $connection->external_account_id,
            'external_pos_id' => $data['external_pos_id'],
        ];
        $existing = ProviderPosLocation::query()->where($identity)->first();
        abort_if($existing !== null && ($existing->property_id !== $property->id
            || $existing->integration_connection_id !== $connection->id), 422,
            'This provider POS is already bound to another property or connection.');
        $location = ProviderPosLocation::query()->updateOrCreate($identity, [
            'property_id' => $property->id,
            'integration_connection_id' => $connection->id,
            'provider_store_id' => $data['provider_store_id'],
            'display_name' => $data['display_name'],
            'qr_mode' => $data['qr_mode'],
            'is_enabled' => true,
            'health_state' => 'configured',
            'last_synced_at' => now(),
        ]);

        return (new ProviderPosLocationResource($location))->response()->setStatusCode(201);
    }

    public function setTerminalState(Request $request, PaymentTerminal $paymentTerminal): PaymentTerminalResource
    {
        $this->authorize('update', $paymentTerminal);
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        if (! $data['enabled'] && $paymentTerminal->attempts()->whereIn('state', $this->activeStates())->exists()) {
            abort(409, 'Drain or cancel the active order before disabling this terminal.');
        }
        $paymentTerminal->update(['is_enabled' => $data['enabled'], 'disabled_at' => $data['enabled'] ? null : now()]);

        return new PaymentTerminalResource($paymentTerminal->fresh());
    }

    public function setPosState(Request $request, ProviderPosLocation $providerPosLocation): ProviderPosLocationResource
    {
        $this->authorize('update', $providerPosLocation);
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        if (! $data['enabled'] && $providerPosLocation->attempts()->whereIn('state', $this->activeStates())->exists()) {
            abort(409, 'Drain or cancel the active order before disabling this POS.');
        }
        $providerPosLocation->update(['is_enabled' => $data['enabled'], 'disabled_at' => $data['enabled'] ? null : now()]);

        return new ProviderPosLocationResource($providerPosLocation->fresh());
    }

    public function point(Request $request, Reservation $reservation, InitiateInPersonPayment $command): JsonResponse
    {
        return $this->initiate($request, $reservation, $command, PaymentChannel::IntegratedTerminal, 'terminal_id');
    }

    public function qr(Request $request, Reservation $reservation, InitiateInPersonPayment $command): JsonResponse
    {
        return $this->initiate($request, $reservation, $command, PaymentChannel::Qr, 'pos_location_id');
    }

    public function show(PaymentAttempt $paymentAttempt): PaymentAttemptResource
    {
        $this->authorize('view', $paymentAttempt);

        return new PaymentAttemptResource($paymentAttempt);
    }

    public function cancel(Request $request, PaymentAttempt $paymentAttempt, CancelInPersonOrder $command): PaymentAttemptResource
    {
        $this->authorize('cancel', $paymentAttempt);

        return new PaymentAttemptResource($command->handle($paymentAttempt, $this->key($request)));
    }

    public function reconcile(PaymentAttempt $paymentAttempt, ReconcileInPersonOrder $command): PaymentAttemptResource
    {
        $this->authorize('reconcile', $paymentAttempt);

        return new PaymentAttemptResource($command->handle($paymentAttempt));
    }

    public function refund(Request $request, ReservationChange $refund, ExecuteInPersonRefund $command): JsonResponse
    {
        $this->authorize('completeRefund', $refund->reservation);
        $execution = $command->handle($refund, $request->user()->id);

        return response()->json(['data' => [
            'id' => $execution->id,
            'state' => $execution->state->value,
            'provider_refund_id' => $execution->provider_refund_id,
            'provider_action_required' => $execution->provider_action_required,
            'provider_reason' => $execution->provider_reason,
        ]]);
    }

    private function initiate(Request $request, Reservation $reservation, InitiateInPersonPayment $command, PaymentChannel $channel, string $targetField): JsonResponse
    {
        abort_unless(app(TenantContext::class)->canAccessProperty($reservation->property_id), 403);
        $this->authorize('create', PaymentRequest::class);
        $data = $request->validate([
            $targetField => ['required', 'uuid'],
            'purpose' => ['required', Rule::enum(PaymentRequestPurpose::class)],
            'deposit_id' => ['nullable', 'uuid'],
            'amount_minor' => ['nullable', 'integer', 'min:1'],
        ]);
        $attempt = $command->handle(
            $reservation,
            $channel,
            $data[$targetField],
            PaymentRequestPurpose::from($data['purpose']),
            $data['deposit_id'] ?? null,
            $data['amount_minor'] ?? null,
            $request->user()->id,
            $this->key($request),
        );

        return (new PaymentAttemptResource($attempt))->response()->setStatusCode(201);
    }

    /** @return list<string> */
    private function activeStates(): array
    {
        return ['creating', 'checkout_ready', 'pending', 'queued', 'at_terminal', 'action_required', 'processing'];
    }

    private function key(Request $request): string
    {
        return (string) ($request->header('Idempotency-Key') ?: 'api-'.hash('sha256', $request->method().'|'.$request->path().'|'.json_encode($request->input(), JSON_THROW_ON_ERROR)));
    }
}
