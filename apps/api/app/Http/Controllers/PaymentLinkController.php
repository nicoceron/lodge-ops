<?php

namespace App\Http\Controllers;

use App\Enums\PaymentRequestState;
use App\Models\DirectBookingOrder;
use App\Models\DirectBookingPropertySetting;
use App\Models\ExchangeRate;
use App\Models\PaymentAttempt;
use App\Models\Tenant;
use App\Services\Payments\CreateProviderCheckout;
use App\Services\Payments\PaymentConnectionResolver;
use App\Services\Payments\ResolvePaymentRequest;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PaymentLinkController extends Controller
{
    public function show(string $token, ResolvePaymentRequest $resolver): View
    {
        $paymentRequest = $resolver->handle($token);
        abort_if($paymentRequest === null, 404);
        $rate = $paymentRequest->source_currency === 'USD'
            ? ExchangeRate::query()->where(fn ($query) => $query->where('property_id', $paymentRequest->property_id)->orWhereNull('property_id'))
                ->where('base_currency', 'USD')->where('quote_currency', 'ARS')->where('effective_at', '>=', now()->subDay())
                ->where('effective_at', '<=', now())
                ->orderByDesc('effective_at')->first()
            : null;

        $conversion = $rate === null ? null : [
            'rate_id' => $rate->id,
            'rate' => $rate->rate,
            'source' => $rate->source,
            'effective_at' => $rate->effective_at,
            'charge_amount_minor' => BigDecimal::of($paymentRequest->source_amount_minor)
                ->multipliedBy($rate->rate)->toScale(0, RoundingMode::HalfUp)->toInt(),
        ];

        return view('payments.show', ['paymentRequest' => $paymentRequest, 'token' => $token, 'conversion' => $conversion]);
    }

    public function checkout(Request $request, string $token, ResolvePaymentRequest $resolver, CreateProviderCheckout $checkout, PaymentConnectionResolver $connections): RedirectResponse
    {
        $paymentRequest = $resolver->handle($token);
        abort_if($paymentRequest === null || ! in_array($paymentRequest->state, [PaymentRequestState::Open, PaymentRequestState::Processing], true), 409);
        $connection = $connections->forProperty($paymentRequest->tenant_id, $paymentRequest->property_id);
        $attempt = $checkout->handle(
            $paymentRequest,
            $connection,
            $request->boolean('accept_conversion'),
            $request->string('conversion_rate_id')->toString() ?: null,
        );

        return redirect()->away($attempt->hosted_checkout_url);
    }

    public function returned(string $externalReference): View
    {
        $attempt = PaymentAttempt::withoutGlobalScopes()->where('external_reference', $externalReference)->firstOrFail();
        app(TenantContext::class)->set(Tenant::query()->findOrFail($attempt->tenant_id));
        $directBookingReturnUrl = null;
        $directBookingOrder = DirectBookingOrder::withoutGlobalScopes()
            ->where('reservation_id', $attempt->reservation_id)
            ->first();
        $setting = $directBookingOrder === null ? null : DirectBookingPropertySetting::withoutGlobalScopes()
            ->where('property_id', $directBookingOrder->property_id)
            ->first();
        if ($directBookingOrder !== null && $setting !== null) {
            $directBookingReturnUrl = route('direct-booking.status', [
                $setting->public_slug,
                $directBookingOrder->public_reference,
            ]);
        }

        return view('payments.return', [
            'attempt' => $attempt->fresh(),
            'directBookingReturnUrl' => $directBookingReturnUrl,
        ]);
    }
}
