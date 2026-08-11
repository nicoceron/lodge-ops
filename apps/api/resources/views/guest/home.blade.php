@extends('guest.layout')

@section('title', 'Your stay')

@section('content')
<div class="stack">
    <section>
        <span class="pill">{{ str($reservation['status'])->headline() }}</span>
        <h1>Welcome, {{ $reservation['guest']['preferred_name'] }}</h1>
        <p class="lede">Your stay at {{ $reservation['property']['name'] }} · Confirmation {{ $reservation['confirmation_number'] }}</p>
    </section>

    <div class="grid three">
        <div class="card"><div class="muted">Arrival</div><div class="stat">{{ \Carbon\CarbonImmutable::parse($reservation['starts_at'])->timezone($reservation['property']['timezone'])->format('M j') }}</div><div>{{ \Carbon\CarbonImmutable::parse($reservation['starts_at'])->timezone($reservation['property']['timezone'])->format('H:i') }}</div></div>
        <div class="card"><div class="muted">Departure</div><div class="stat">{{ \Carbon\CarbonImmutable::parse($reservation['ends_at'])->timezone($reservation['property']['timezone'])->format('M j') }}</div><div>{{ \Carbon\CarbonImmutable::parse($reservation['ends_at'])->timezone($reservation['property']['timezone'])->format('H:i') }}</div></div>
        <div class="card"><div class="muted">Room</div><div class="stat">{{ $reservation['room'] ?? 'To be assigned' }}</div><div>{{ $reservation['adults'] + $reservation['children'] }} guests</div></div>
    </div>

    <div class="grid two">
        <section class="card">
            <h2>Before you arrive</h2>
            @foreach ([
                ['Pre-arrival details', $readiness['pre_arrival'], route('guest.portal.pre-arrival')],
                ['Documents', $readiness['waiver'], route('guest.portal.documents')],
                ['Payment', $readiness['payment'], route('guest.portal.payments')],
            ] as [$label, $complete, $url])
                <div class="row"><a href="{{ $url }}">{{ $label }}</a><span class="pill">{{ $complete ? 'Complete' : 'Action needed' }}</span></div>
            @endforeach
        </section>
        <section class="card">
            <h2>Property</h2>
            <p><strong>{{ $reservation['property']['name'] }}</strong></p>
            @if ($reservation['property']['address'])<p class="muted">{{ $reservation['property']['address'] }}</p>@endif
            <p class="muted">All times shown in {{ $reservation['property']['timezone'] }}.</p>
        </section>
    </div>

    <section class="card">
        <h2>Itinerary</h2>
        @forelse ($itinerary as $item)
            <div class="row"><div><strong>{{ $item['title'] }}</strong><div class="muted">{{ $item['detail'] }}</div></div><div>{{ \Carbon\CarbonImmutable::parse($item['starts_at'])->timezone($reservation['property']['timezone'])->format('D M j · H:i') }}</div></div>
        @empty
            <p class="muted">Your detailed itinerary will appear here when activities are assigned.</p>
        @endforelse
    </section>
</div>
@endsection
