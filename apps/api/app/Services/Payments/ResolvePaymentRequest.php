<?php

namespace App\Services\Payments;

use App\Enums\PaymentRequestState;
use App\Models\PaymentRequest;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

final class ResolvePaymentRequest
{
    public function handle(string $token): ?PaymentRequest
    {
        $hash = hash('sha256', $token);
        $candidate = PaymentRequest::withoutGlobalScopes()->where('access_token_hash', $hash)->first();
        if ($candidate === null || ! hash_equals($candidate->access_token_hash, $hash)) {
            return null;
        }
        app(TenantContext::class)->set(Tenant::query()->findOrFail($candidate->tenant_id));

        return DB::transaction(function () use ($candidate): PaymentRequest {
            $request = PaymentRequest::query()->lockForUpdate()->findOrFail($candidate->id);
            if (in_array($request->state, [PaymentRequestState::Open, PaymentRequestState::Processing], true) && $request->expires_at->isPast()) {
                $request->attempts()->whereIn('state', ['creating', 'checkout_ready', 'pending'])->update([
                    'state' => 'expired',
                    'last_error' => 'Payment request expired before authoritative approval.',
                    'last_processed_at' => now(),
                ]);
                $request->update(['state' => PaymentRequestState::Expired]);
            }
            $request->update([
                'opened_at' => $request->opened_at ?? now(),
                'last_opened_at' => now(),
                'access_count' => min(4_294_967_295, $request->access_count + 1),
            ]);

            return $request->fresh(['property', 'reservation']);
        }, 3);
    }
}
