@extends('documents.layout')
@section('content')
<h1>Payment receipt</h1>
@include('documents._reservation')
<h2>Payment</h2>
<table><tr><th>Status</th><td>{{ $snapshot['payload']['payment']['status'] }}</td></tr><tr><th>Method</th><td>{{ $snapshot['payload']['payment']['method'] }}</td></tr><tr><th>Record</th><td>{{ $snapshot['payload']['payment']['wording'] }}</td></tr><tr><th>Reference</th><td>{{ $snapshot['payload']['payment']['reference'] ?? '—' }}</td></tr><tr><th>Amount</th><td>{{ $snapshot['payload']['payment']['currency'] }} {{ number_format($snapshot['payload']['payment']['amount_minor'] / 100, 2) }}</td></tr></table>
<p>This receipt confirms a payment record in LodgeOps. It does not claim provider settlement or fiscal validity.</p>
@endsection
