@extends('guest.layout')

@section('title', 'Documents')

@section('content')
<div class="stack">
    <section><h1>Documents</h1><p class="lede">Review the current document version before acknowledging it.</p></section>
    <section class="card stack" aria-labelledby="generated-documents-title">
        <div><span class="pill">PDF copies</span><h2 id="generated-documents-title">Generated stay documents</h2></div>
        @forelse ($generated_documents as $generated)
            <div class="row"><div><strong>{{ str($generated['kind'])->replace('_', ' ')->title() }}</strong><div class="muted">Generated {{ \Carbon\CarbonImmutable::parse($generated['generated_at'])->format('M j, Y') }}</div></div><a class="button" href="{{ $generated['download_url'] }}" aria-label="Download {{ $generated['file_name'] }}">Download PDF</a></div>
        @empty
            <p class="muted">No generated stay documents are available yet.</p>
        @endforelse
    </section>
    @if ($document)
        <section class="card stack">
            <div><span class="pill">Version {{ $document['version'] }}</span><h2>{{ $document['title'] }}</h2></div>
            <div>{!! nl2br(e($document['body'])) !!}</div>
            @if ($document['acknowledged'])
                <div class="notice">Acknowledged by {{ $document['signature'] }} on {{ \Carbon\CarbonImmutable::parse($document['acknowledged_at'])->format('M j, Y') }}.</div>
            @else
                <form class="stack" method="post" action="{{ route('guest.portal.documents.acknowledge') }}">
                    @csrf
                    <input type="hidden" name="document_slug" value="{{ $document['slug'] }}">
                    <input type="hidden" name="document_version" value="{{ $document['version'] }}">
                    <input type="hidden" name="document_hash" value="{{ $document['body_hash'] }}">
                    <label>Type your full name as your signature<input name="signature" required minlength="3" maxlength="200" value="{{ old('signature') }}"></label>
                    <label><input type="checkbox" name="accepted" value="1" required>I have read and accept this document.</label>
                    <div><button type="submit">Acknowledge document</button></div>
                </form>
            @endif
        </section>
    @else
        <section class="card"><p class="muted">There are no active documents for this stay.</p></section>
    @endif
</div>
@endsection
