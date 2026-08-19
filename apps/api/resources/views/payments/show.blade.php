<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Secure payment · {{ $paymentRequest->property->name }}</title>
<style>body{font-family:ui-sans-serif,system-ui;background:#f6f7f4;color:#183027;margin:0}.card{max-width:34rem;margin:8vh auto;background:white;border:1px solid #dfe7e2;border-radius:1rem;padding:2rem;box-shadow:0 1rem 3rem #18302714}.eyebrow{color:#507062;text-transform:uppercase;letter-spacing:.08em;font-size:.75rem}.amount{font-size:2.25rem;font-weight:750;margin:.35rem 0}.meta{color:#5c6f67}.notice{background:#f2f7f4;border-radius:.75rem;padding:1rem;margin:1.25rem 0}button{width:100%;border:0;border-radius:.75rem;padding:1rem;background:#176b4d;color:white;font-weight:700;font-size:1rem}button:disabled{background:#9aa7a1}.terminal{border-left:.3rem solid #7c8b84;padding-left:1rem}@media(max-width:600px){.card{margin:0;min-height:100vh;border-radius:0;padding:1.4rem}}</style></head>
<body><main class="card"><p class="eyebrow">{{ $paymentRequest->property->name }} · secure payment</p><h1>Reservation {{ $paymentRequest->reservation->confirmation_number }}</h1>
<p class="amount">{{ $paymentRequest->source_currency }} {{ number_format($paymentRequest->source_amount_minor / 100, 2) }}</p><p class="meta">{{ str_replace('_', ' ', ucfirst($paymentRequest->purpose->value)) }} · expires {{ $paymentRequest->expires_at->timezone($paymentRequest->property->timezone)->format('M j, Y H:i') }}</p>
@if(in_array($paymentRequest->state->value, ['open', 'processing']))
<div class="notice">You will complete payment in Mercado Pago. Inn never receives or stores your card details. Final confirmation is based on the payment provider, not the browser return.</div>
<form method="post" action="{{ route('payment-link.checkout', ['token' => $token], false) }}">@csrf
@if($paymentRequest->source_currency === 'USD')
 @if($conversion)<input type="hidden" name="conversion_rate_id" value="{{ $conversion['rate_id'] }}"><label><input type="checkbox" name="accept_conversion" value="1" required> I accept paying ARS {{ number_format($conversion['charge_amount_minor']/100,2) }} for USD {{ number_format($paymentRequest->source_amount_minor/100,2) }} at {{ $conversion['rate'] }} ARS/USD using {{ $conversion['source'] }} ({{ $conversion['effective_at']->format('Y-m-d H:i T') }}).</label><br><br>@else <p class="terminal">Online payment is unavailable because no current ARS conversion snapshot exists. Please use bank transfer.</p>@endif
@endif
<button type="submit" @disabled($paymentRequest->source_currency === 'USD' && !$conversion)>Pay securely with Mercado Pago</button></form>
@else <div class="terminal"><h2>{{ ucfirst($paymentRequest->state->value) }}</h2><p>This payment link cannot start another charge. Contact the property if you need assistance.</p></div>@endif
</main></body></html>
