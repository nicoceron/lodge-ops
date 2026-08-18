@extends('documents.layout')
@section('content')
<h1>{{ $snapshot['payload']['waiver']['title'] }}</h1>
@include('documents._reservation')
<h2>Acknowledged text</h2><div>{!! nl2br(e($snapshot['payload']['waiver']['body'])) !!}</div>
<h2>Acknowledgement</h2><p>Acknowledged by {{ $snapshot['payload']['waiver']['acknowledged_by'] }} at {{ $snapshot['payload']['waiver']['acknowledged_at']['local'] }}.</p>
<p class="muted">Document version {{ $snapshot['payload']['waiver']['version'] }} · SHA-256 {{ $snapshot['payload']['waiver']['body_hash'] }}</p>
@endsection
