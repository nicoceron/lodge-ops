@extends('documents.layout')
@section('content')
<h1>Refund receipt</h1>
@include('documents._reservation')
<h2>Completed refund</h2>
<table><tr><th>Reference</th><td>{{ $snapshot['payload']['refund']['reference'] ?? '—' }}</td></tr><tr><th>Reason</th><td>{{ $snapshot['payload']['refund']['reason'] ?? 'Not specified' }}</td></tr><tr><th>Completed</th><td>{{ $snapshot['payload']['refund']['completed_at']['local'] }}</td></tr><tr><th>Amount</th><td>{{ $snapshot['payload']['refund']['currency'] }} {{ number_format($snapshot['payload']['refund']['amount_minor'] / 100, 2) }}</td></tr></table>
<p>This receipt records a completed refund event and is not a fiscal credit note.</p>
@endsection
