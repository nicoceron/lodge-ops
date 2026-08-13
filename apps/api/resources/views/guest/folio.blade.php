@extends('guest.layout')

@section('title', 'Folio')

@section('content')
<div class="stack">
    <section><span class="pill">{{ $folio['is_final'] ? 'Final folio' : 'Provisional folio' }}</span><h1>Folio</h1><p class="lede">Confirmation {{ $folio['confirmation_number'] }}</p></section>
    <section class="card">
        <div style="overflow-x:auto">
            <table>
                <thead><tr><th>Posted</th><th>Description</th><th>Type</th><th class="amount">Net</th><th class="amount">Tax</th><th class="amount">Gross</th></tr></thead>
                <tbody>
                    @forelse ($folio['lines'] as $line)
                        <tr><td>{{ \Carbon\CarbonImmutable::parse($line['posted_at'])->format('M j, Y') }}</td><td>{{ $line['description'] }}</td><td>{{ str($line['type'])->headline() }}</td><td class="amount">{{ $folio['currency'] }} {{ number_format($line['net_amount_minor'] / 100, 2) }}</td><td class="amount">{{ $folio['currency'] }} {{ number_format($line['tax_amount_minor'] / 100, 2) }}</td><td class="amount">{{ $folio['currency'] }} {{ number_format($line['gross_amount_minor'] / 100, 2) }}</td></tr>
                    @empty
                        <tr><td colspan="6" class="muted">No extra charges, credits, or payments have been posted.</td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr><th colspan="5">Booked total ({{ $folio['currency'] }} {{ number_format($folio['booked_net_minor'] / 100, 2) }} net + {{ $folio['currency'] }} {{ number_format($folio['booked_tax_minor'] / 100, 2) }} tax)</th><th class="amount">{{ $folio['currency'] }} {{ number_format($folio['booked_total_minor'] / 100, 2) }}</th></tr>
                    <tr><th colspan="5">Balance</th><th class="amount">{{ $folio['currency'] }} {{ number_format($folio['balance_minor'] / 100, 2) }}</th></tr>
                </tfoot>
            </table>
        </div>
    </section>
</div>
@endsection
