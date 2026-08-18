@extends('documents.layout')
@section('content')
<h1>Reservation confirmation</h1>
@include('documents._reservation')
@include('documents._allocations')
<h2>Balance</h2>
<p class="amount">{{ $snapshot['payload']['reservation']['currency'] }} {{ number_format($snapshot['payload']['reservation']['balance_minor'] / 100, 2) }}</p>
<p>This confirmation records the booking details held by the property. It is not a fiscal invoice or tax receipt.</p>
@endsection
