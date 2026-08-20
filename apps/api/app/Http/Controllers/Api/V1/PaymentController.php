<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $service,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Payment::class);

        $propertyId = $this->tenantContext->membership()?->property_id;
        $payments = Payment::query()
            ->when($propertyId, fn ($query) => $query->whereHas(
                'reservation',
                fn ($reservation) => $reservation->where('property_id', $propertyId),
            ))
            ->when($request->query('reservation_id'), fn ($query, $value) => $query->where('reservation_id', $value))
            ->latest()
            ->paginate(min((int) $request->integer('per_page', 50), 100));

        return PaymentResource::collection($payments);
    }

    public function show(Payment $payment): PaymentResource
    {
        $this->authorize('view', $payment);

        return new PaymentResource($payment);
    }

    public function reconcile(Request $request, Payment $payment): PaymentResource
    {
        $this->authorize('reconcile', $payment);
        $validated = $request->validate([
            'deposit_id' => ['nullable', 'uuid'],
            'evidence_url' => ['nullable', 'url:http,https', 'max:2000'],
            'evidence_note' => ['nullable', 'string', 'max:5000'],
        ]);
        if (array_key_exists('evidence_url', $validated) || array_key_exists('evidence_note', $validated)) {
            $payment->update(array_filter([
                'evidence_url' => $validated['evidence_url'] ?? null,
                'evidence_note' => $validated['evidence_note'] ?? null,
            ], fn ($value) => $value !== null));
        }

        return new PaymentResource($this->service->reconcile($payment, $request->user()->id, $validated['deposit_id'] ?? null));
    }

    public function reverse(Request $request, Payment $payment): PaymentResource
    {
        $this->authorize('reverse', $payment);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:5000']]);

        return new PaymentResource($this->service->reverse($payment, $validated['reason'], $request->user()->id));
    }
}
