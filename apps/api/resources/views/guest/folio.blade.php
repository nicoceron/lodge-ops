@extends('guest.layout')

@section('title', 'Folio')

@section('content')
<div class="stack">
    <section><span class="pill">{{ $folio['is_final'] ? 'Final folio' : 'Provisional folio' }}</span><h1>Folio</h1><p class="lede">Confirmation {{ $folio['confirmation_number'] }}</p></section>
    <section class="card">
        <div style="overflow-x:auto">
            <table>
                <thead><tr><th>Posted</th><th>Description</th><th>Type</th><th class="amount">Amount</th></tr></thead>
                <tbody>
                    @forelse ($folio['lines'] as $line)
                        <tr><td>{{ \Carbon\CarbonImmutable::parse($line['posted_at'])->format('M j, Y') }}</td><td>{{ $line['description'] }}</td><td>{{ str($line['type'])->headline() }}</td><td class="amount">{{ $folio['currency'] }} {{ number_format($line['amount_minor'] / 100, 2) }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="muted">No folio lines have been posted.</td></tr>
                    @endforelse
                </tbody>
                <tfoot><tr><th colspan="3">Balance</th><th class="amount">{{ $folio['currency'] }} {{ number_format($folio['balance_minor'] / 100, 2) }}</th></tr></tfoot>
            </table>
        </div>
    </section>
</div>
@endsection
