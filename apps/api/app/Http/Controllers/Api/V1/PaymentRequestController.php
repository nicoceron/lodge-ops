<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentRequestPurpose;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentRequestResource;
use App\Models\PaymentRequest;
use App\Models\Reservation;
use App\Services\Payments\IssuePaymentRequest;
use App\Services\Payments\RevokeOrSupersedePaymentRequest;
use App\Services\Payments\RotateOrResendPaymentRequest;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentRequestController extends Controller
{
    public function index(Reservation $reservation)
    {
        abort_unless(app(TenantContext::class)->canAccessProperty($reservation->property_id), 403);
        $this->authorize('viewAny', PaymentRequest::class);

        return PaymentRequestResource::collection($reservation->paymentRequests()->latest()->get());
    }

    public function store(Request $request, Reservation $reservation, IssuePaymentRequest $command): JsonResponse
    {
        abort_unless(app(TenantContext::class)->canAccessProperty($reservation->property_id), 403);
        $this->authorize('create', PaymentRequest::class);
        $data = $request->validate([
            'purpose' => ['required', Rule::enum(PaymentRequestPurpose::class)],
            'deposit_id' => ['nullable', 'uuid'],
            'amount_minor' => ['nullable', 'integer', 'min:1'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);
        $issued = $command->handle(
            $reservation,
            PaymentRequestPurpose::from($data['purpose']),
            $data['deposit_id'] ?? null,
            $data['amount_minor'] ?? null,
            $request->user()->id,
            $data['expires_at'] ?? null,
        );

        return response()->json([
            'data' => (new PaymentRequestResource($issued->request))->resolve($request),
            'access' => [
                'url' => url('/pay/'.$issued->token),
                'token_rotates_on_secure_resend' => true,
            ],
        ], 201);
    }

    public function show(PaymentRequest $paymentRequest): PaymentRequestResource
    {
        $this->authorize('view', $paymentRequest);

        return new PaymentRequestResource($paymentRequest->load('attempts'));
    }

    public function rotate(Request $request, PaymentRequest $paymentRequest, RotateOrResendPaymentRequest $command): JsonResponse
    {
        $this->authorize('update', $paymentRequest);
        $issued = $command->handle($paymentRequest, true, $request->user()->id);

        return response()->json([
            'data' => (new PaymentRequestResource($issued->request))->resolve($request),
            'access' => ['url' => url('/pay/'.$issued->token)],
        ]);
    }

    public function revoke(Request $request, PaymentRequest $paymentRequest, RevokeOrSupersedePaymentRequest $command): PaymentRequestResource
    {
        $this->authorize('update', $paymentRequest);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return new PaymentRequestResource($command->handle($paymentRequest, $data['reason'], $request->user()->id));
    }
}
