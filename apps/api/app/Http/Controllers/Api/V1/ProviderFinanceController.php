<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PaymentAttempt;
use App\Models\ReservationChange;
use App\Services\Payments\ExecuteProviderRefund;
use App\Services\Payments\ReconcileProviderPayment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProviderFinanceController extends Controller
{
    public function reconcile(Request $request, PaymentAttempt $paymentAttempt, ReconcileProviderPayment $command): JsonResponse
    {
        abort_unless(app(TenantContext::class)->membership()?->role->canManageMoney() === true, 403);
        $event = $command->handle($paymentAttempt);

        return response()->json(['data' => ['event_id' => $event->id, 'state' => $event->processing_state->value]]);
    }

    public function refund(Request $request, ReservationChange $refund, ExecuteProviderRefund $command): JsonResponse
    {
        abort_unless(app(TenantContext::class)->membership()?->role->canManageMoney() === true, 403);
        $execution = $command->handle($refund, $request->user()->id);

        return response()->json(['data' => ['id' => $execution->id, 'state' => $execution->state->value, 'provider_refund_id' => $execution->provider_refund_id]]);
    }
}
