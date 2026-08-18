@extends('guest.layout')

@section('title', 'Payment')

@section('content')
<div class="stack">
    <section><h1>Payment</h1><p class="lede">Review the outstanding balance or provide evidence of a manual transfer.</p></section>
    <div class="grid two">
        <section class="card"><div class="muted">Outstanding balance</div><div class="stat">{{ $payment['currency'] }} {{ number_format($payment['balance_minor'] / 100, 2) }}</div>@if($payment['balance_minor'] === 0)<p class="pill">Paid</p>@endif</section>
        <section class="card stack">
            <h2>Manual payment evidence</h2>
            @if ($payment['evidence'])
                <div class="notice">
                    {{ $payment['evidence']['file_name'] }} · {{ str($payment['evidence']['status'])->headline() }}
                    · {{ $payment['evidence']['currency'] }} {{ number_format($payment['evidence']['amount_minor'] / 100, 2) }}
                    @if($payment['evidence']['requested_information_note'])<br>{{ $payment['evidence']['requested_information_note'] }}@endif
                    @if($payment['evidence']['reviewer_note'])<br>{{ $payment['evidence']['reviewer_note'] }}@endif
                </div>
            @endif
            <form class="stack" method="post" action="{{ route('guest.portal.payments.store') }}" enctype="multipart/form-data">
                @csrf
                <label>Transferred amount (minor units)<input type="number" name="amount_minor" min="1" required value="{{ old('amount_minor', $payment['balance_minor']) }}"></label>
                <label>Currency<input name="currency" required minlength="3" maxlength="3" value="{{ old('currency', $payment['currency']) }}"></label>
                <label>Bank transfer reference<input name="transfer_reference" maxlength="200" value="{{ old('transfer_reference') }}"></label>
                <label>Receipt or transfer evidence<input type="file" name="evidence" required accept="application/pdf,image/jpeg,image/png"></label>
                <p class="muted">PDF, JPG or PNG. Maximum 10 MB. Submissions are reviewed by lodge staff and do not automatically create a payment.</p>
                <div><button type="submit">Submit evidence</button></div>
            </form>
        </section>
    </div>
</div>
@endsection
