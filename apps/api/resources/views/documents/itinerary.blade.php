@extends('documents.layout')
@section('content')
<h1>Itinerary</h1>
@include('documents._reservation')
@include('documents._allocations')
@if($snapshot['payload']['guest_notes'])<h2>Guest notes</h2>@foreach($snapshot['payload']['guest_notes'] as $note)<p>{{ $note }}</p>@endforeach@endif
@endsection
