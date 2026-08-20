@extends('documents.layout')
@section('content')
<h1>Payment receipt</h1>
@include('documents._reservation')
<h2>Payment</h2>
<table><tr><th>Status</th><td>{{ $snapshot['payload']['payment']['status'] }}</td></tr><tr><th>Channel</th><td>{{ str_replace('_', ' ', $snapshot['payload']['payment']['channel']) }}</td></tr><tr><th>Record</th><td>{{ $snapshot['payload']['payment']['wording'] }}</td></tr><tr><th>Reference</th><td>{{ $snapshot['payload']['payment']['reference'] ?? '—' }}</td></tr>@if($snapshot['payload']['payment']['card_last_four'] ?? null)<tr><th>Card</th><td>{{ $snapshot['payload']['payment']['card_brand'] ?? 'Card' }} ending {{ $snapshot['payload']['payment']['card_last_four'] }}</td></tr>@endif<tr><th>Amount</th><td>{{ $snapshot['payload']['payment']['currency'] }} {{ number_format($snapshot['payload']['payment']['amount_minor'] / 100, 2) }}</td></tr></table>
<p>This receipt confirms a payment record in LodgeOps. It does not claim provider settlement or fiscal validity.</p>
@endsection
