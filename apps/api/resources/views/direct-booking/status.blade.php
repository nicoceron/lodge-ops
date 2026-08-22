@extends('direct-booking.layout')

@section('title', __('direct-booking.states.'.$status['state'].'.title'))

@section('content')
@php
    $state = $status['state'];
    $pollingStates = ['payment_pending','evidence_pending','finance_review','paid_pending_confirmation'];
    $deadline = $status['checkout_expires_at'] ?? $status['hold_expires_at'] ?? $status['quote_expires_at'] ?? $status['session_expires_at'] ?? null;
    $paymentLabel = match ($state) {
        'held', 'expired', 'payment_failed', 'evidence_rejected' => __('direct-booking.status.not_paid'),
        'confirmed' => __('direct-booking.status.paid'),
        'paid_needs_review' => __('direct-booking.status.paid_review'),
        'payment_pending', 'paid_pending_confirmation' => __('direct-booking.status.payment_pending'),
        default => __('direct-booking.status.not_paid'),
    };
@endphp
<div class="status-shell" data-analytics-event="{{ $state === 'confirmed' ? 'confirmation_viewed' : 'status_viewed' }}">
    @include('direct-booking._errors')
    <section class="status-card booking-card state-{{ $state }}" @if(in_array($state, $pollingStates, true)) data-status-poll="{{ route('direct-booking.poll', [$propertySlug, $orderReference] + $fixtureQuery) }}" data-initial-state="{{ $state }}" @endif>
        <p class="eyebrow">{{ __('direct-booking.status.eyebrow') }}</p>
        <div class="state-mark" aria-hidden="true"></div>
        <h1>{{ __('direct-booking.states.'.$state.'.title') }}</h1>
        <p class="lede">{{ __('direct-booking.states.'.$state.'.body') }}</p>
        <dl class="status-meta">
            <div><dt>{{ __('direct-booking.status.reference') }}</dt><dd>{{ $orderReference }}</dd></div>
            @if(isset($quote['total']))
                <div><dt>{{ __('direct-booking.review.nights') }}</dt><dd>{{ __('direct-booking.review.nights_value', ['count' => collect($quote['lines'] ?? [])->where('type', 'nightly_rate')->count()]) }}</dd></div>
                <div><dt>{{ __('direct-booking.review.total') }}</dt><dd>{{ \App\View\DirectBookingPresenter::money($quote['total'], $locale) }}</dd></div>
                @if(isset($quote['deposit']))<div><dt>{{ __('direct-booking.review.deposit') }}</dt><dd>{{ \App\View\DirectBookingPresenter::money($quote['deposit'], $locale) }}</dd></div>@endif
            @endif
            @if($state !== 'confirmed')
                <div><dt>{{ __('direct-booking.status.payment') }}</dt><dd>{{ $paymentLabel }}</dd></div>
            @endif
            @if($deadline)<div><dt>{{ __('direct-booking.status.expired_at', ['time' => '']) }}</dt><dd>{{ \App\View\DirectBookingPresenter::dateTime($deadline, $property['timezone'], $locale) }}</dd></div>@endif
        </dl>
        <p class="authority-note">{{ __('direct-booking.status.last_checked') }}</p>
        <div class="status-live" aria-live="polite" aria-atomic="true" data-status-live data-checking="{{ __('direct-booking.status.checking') }}" data-offline="{{ __('direct-booking.status.offline') }}" data-changed="{{ __('direct-booking.status.changed') }}"></div>
    </section>

    @if($state === 'held' && in_array('checkout', $status['actions'], true))
        <section class="booking-card" aria-labelledby="payment-title">
            <h2 id="payment-title">{{ __('direct-booking.status.payment_title') }}</h2>
            <form method="post" action="{{ route('direct-booking.checkout', [$propertySlug, $orderReference] + $fixtureQuery) }}" class="booking-form" data-disable-submit data-analytics-submit="checkout_selected">
                @csrf
                <input type="hidden" name="expected_state_version" value="{{ $status['state_version'] }}">
                <input type="hidden" name="checkout_idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                <div class="payment-methods">
                    @foreach($status['payment_capabilities'] as $index => $capability)
                        <label class="payment-method"><input type="radio" name="method" value="{{ $capability['method'] }}" required @checked($index === 0)><span><strong>{{ __('direct-booking.status.'.$capability['method']) }}</strong><small>{{ $capability['currency'] }} · {{ __('direct-booking.status.'.($capability['method'] === 'hosted_checkout' ? 'hosted_hint' : 'manual_hint')) }}</small></span></label>
                    @endforeach
                </div>
                <button class="button wide" type="submit">{{ __('direct-booking.status.continue_payment') }}</button>
            </form>
        </section>
    @endif

    @if($state === 'awaiting_manual_payment')
        <section class="booking-card manual-panel" aria-labelledby="manual-title">
            @if($manualInstructions)
                <p class="eyebrow">{{ __('direct-booking.manual.version', ['version' => $manualInstructions['version']]) }}</p>
                <h2 id="manual-title">{{ $manualInstructions['title'] }}</h2>
                <p>{{ $manualInstructions['body'] }}</p>
            @else
                <h2 id="manual-title">{{ __('direct-booking.status.manual_bank_transfer') }}</h2>
                <div class="notice error" role="alert">{{ __('direct-booking.status.manual_not_ready') }}</div>
            @endif
            @if($manualInstructions && in_array('submit_manual_evidence', $status['actions'], true))
                <form method="post" action="{{ route('direct-booking.evidence', [$propertySlug, $orderReference] + $fixtureQuery) }}" enctype="multipart/form-data" class="booking-form" data-disable-submit>
                    @csrf
                    <input type="hidden" name="expected_state_version" value="{{ $status['state_version'] }}">
                    <input type="hidden" name="evidence_idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <label for="evidence">{{ __('direct-booking.status.evidence_file') }}<input id="evidence" type="file" name="evidence" accept="application/pdf,image/jpeg,image/png" required aria-describedby="evidence-help"><span class="field-hint" id="evidence-help">{{ __('direct-booking.status.evidence_help') }}</span></label>
                    <button class="button" type="submit">{{ __('direct-booking.status.submit_evidence') }}</button>
                </form>
            @endif
        </section>
    @endif

    @if(in_array('retry_payment', $status['actions'], true))
        <section class="booking-card action-panel"><form method="post" action="{{ route('direct-booking.retry-payment', [$propertySlug, $orderReference] + $fixtureQuery) }}" data-disable-submit>@csrf<input type="hidden" name="expected_state_version" value="{{ $status['state_version'] }}"><input type="hidden" name="retry_idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><button class="button" type="submit">{{ __('direct-booking.status.retry_payment') }}</button></form></section>
    @endif

    @if($state === 'expired' && in_array('recover', $status['actions'], true))
        <section class="booking-card action-panel"><h2>{{ __('direct-booking.status.recover_dates') }}</h2><form method="post" action="{{ route('direct-booking.recover', [$propertySlug, $orderReference] + $fixtureQuery) }}" class="booking-form" data-disable-submit>@csrf<input type="hidden" name="expected_state_version" value="{{ $status['state_version'] }}"><input type="hidden" name="recover_idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><div class="form-grid two"><label>{{ __('direct-booking.property.arrival') }}<input type="date" name="arrival_date"></label><label>{{ __('direct-booking.property.departure') }}<input type="date" name="departure_date"></label></div><button class="button" type="submit">{{ __('direct-booking.status.recover') }}</button></form></section>
    @endif

    @if($state === 'confirmed' && $confirmation)
        <section class="booking-card confirmation-panel" aria-labelledby="confirmation-title">
            <p class="eyebrow">{{ __('direct-booking.status.confirmation_title') }}</p>
            <h2 id="confirmation-title">{{ $confirmation['confirmation_number'] }}</h2>
            <div class="confirmation-grid"><div><span>{{ __('direct-booking.property.arrival') }}</span><strong>{{ \App\View\DirectBookingPresenter::date($confirmation['arrival_date'], $locale) }}</strong></div><div><span>{{ __('direct-booking.property.departure') }}</span><strong>{{ \App\View\DirectBookingPresenter::date($confirmation['departure_date'], $locale) }}</strong></div><div><span>{{ __('direct-booking.review.total') }}</span><strong>{{ \App\View\DirectBookingPresenter::money($confirmation['total'], $locale) }}</strong></div></div>
            <h3>{{ __('direct-booking.status.next_steps') }}</h3><p>{{ __('direct-booking.status.next_steps_body') }}</p>
            @if($confirmedDocuments !== [])
                <nav class="confirmation-links" aria-label="{{ __('direct-booking.status.documents') }}">
                    @foreach($confirmedDocuments as $document)
                        <a class="button secondary" href="{{ route('direct-booking.document', [$propertySlug, $orderReference, $document['document_reference']]) }}" data-api-download-path="{{ $document['download_path'] }}" download>{{ __('direct-booking.status.'.$document['kind']) }}</a>
                    @endforeach
                </nav>
            @else
                <p class="notice neutral">{{ __('direct-booking.status.links_pending') }}</p>
            @endif
        </section>
    @endif

    <div class="status-actions"><a class="button secondary" href="{{ route('direct-booking.status', [$propertySlug, $orderReference] + $fixtureQuery) }}">{{ __('direct-booking.status.refresh') }}</a>@if(!empty($property['accessible_fallback_url']))<a href="{{ $property['accessible_fallback_url'] }}">{{ __('direct-booking.fallback') }}</a>@endif</div>
</div>
@endsection
