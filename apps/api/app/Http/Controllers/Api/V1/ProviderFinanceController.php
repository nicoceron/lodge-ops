<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PaymentAttempt;
use App\Models\ProviderDispute;
use App\Models\ProviderRefund;
use App\Models\ReservationChange;
use App\Models\SettlementEntry;
use App\Services\Payments\ExecuteProviderRefund;
use App\Services\Payments\ReconcileProviderPayment;
use App\Services\Payments\RecoverProviderRefund;
use App\Services\Payments\ResolveSettlementVariance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProviderFinanceController extends Controller
{
    public function reconcile(Request $request, PaymentAttempt $paymentAttempt, ReconcileProviderPayment $command): JsonResponse
    {
        $this->authorize('reconcile', $paymentAttempt);
        $event = $command->handle($paymentAttempt);

        return response()->json(['data' => ['event_id' => $event->id, 'state' => $event->processing_state->value]]);
    }

    public function refund(Request $request, ReservationChange $refund, ExecuteProviderRefund $command): JsonResponse
    {
        $this->authorize('completeRefund', $refund->reservation);
        $execution = $command->handle($refund, $request->user()->id);

        return response()->json(['data' => ['id' => $execution->id, 'state' => $execution->state->value, 'provider_refund_id' => $execution->provider_refund_id]]);
    }

    public function recoverRefund(Request $request, ProviderRefund $providerRefund, RecoverProviderRefund $command): JsonResponse
    {
        $this->authorize('recover', $providerRefund);
        $data = $request->validate(['provider_refund_id' => ['required', 'string', 'max:160']]);
        $execution = $command->handle($providerRefund, $data['provider_refund_id'], $request->user()->id);

        return response()->json(['data' => ['id' => $execution->id, 'state' => $execution->state->value, 'provider_refund_id' => $execution->provider_refund_id]]);
    }

    public function settlement(Request $request, SettlementEntry $settlementEntry, ResolveSettlementVariance $command): JsonResponse
    {
        $this->authorize('resolve', $settlementEntry);
        $data = $request->validate([
            'action' => ['required', 'in:investigate,resolve'],
            'notes' => ['required', 'string', 'max:2000'],
        ]);
        $entry = $command->handle($settlementEntry, $data['action'], $data['notes'], $request->user()->id);

        return response()->json(['data' => ['id' => $entry->id, 'state' => $entry->reconciliation_state->value]]);
    }

    public function resolveDispute(Request $request, ProviderDispute $providerDispute): JsonResponse
    {
        $this->authorize('resolve', $providerDispute);
        $data = $request->validate(['notes' => ['required', 'string', 'max:2000']]);
        $providerDispute->update([
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
            'resolution_notes' => $data['notes'],
        ]);

        return response()->json(['data' => ['id' => $providerDispute->id, 'state' => $providerDispute->state->value]]);
    }
}
