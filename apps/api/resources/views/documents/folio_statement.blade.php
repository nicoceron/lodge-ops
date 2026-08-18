@extends('documents.layout')
@section('content')
<h1>{{ $snapshot['payload']['folio']['label'] }} folio statement</h1>
@include('documents._reservation')
<h2>Ledger</h2>
<table><thead><tr><th>Date</th><th>Description</th><th class="amount">Amount</th></tr></thead><tbody>
@foreach($snapshot['payload']['folio']['lines'] as $line)<tr><td>{{ $line['posted_at']['local'] }}</td><td>{{ $line['description'] }}</td><td class="amount">{{ $line['currency'] }} {{ number_format($line['gross_minor'] / 100, 2) }}</td></tr>@endforeach
</tbody></table>
<h2>Balance</h2><p class="amount">{{ $snapshot['payload']['reservation']['currency'] }} {{ number_format($snapshot['payload']['reservation']['balance_minor'] / 100, 2) }}</p>
<p>This folio is an operational account statement and is not a fiscal invoice.</p>
@endsection
