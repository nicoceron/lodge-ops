@extends('guest.layout')

@section('title', 'Message preferences')

@section('content')
<div class="stack">
    <section><h1>Message preferences</h1><p class="lede">Choose optional email messages. Essential reservation, payment, safety, and service messages are not controlled here.</p></section>
    <section class="card stack">
        <form class="stack" method="post" action="{{ route('guest.portal.communication-preferences.update') }}">
            @csrf
            @foreach (['survey' => 'Post-stay feedback invitations', 'marketing' => 'News and offers'] as $purpose => $label)
                @php($record = $communicationPreferences->get($purpose))
                <input type="hidden" name="{{ $purpose }}" value="0">
                <label><input type="checkbox" name="{{ $purpose }}" value="1" @checked($record?->is_allowed === true && $record?->withdrawn_at === null)>{{ $label }}</label>
            @endforeach
            <p class="muted">Clearing a box records an immediate withdrawal for this property.</p>
            <div><button type="submit">Save preferences</button></div>
        </form>
    </section>
</div>
@endsection
