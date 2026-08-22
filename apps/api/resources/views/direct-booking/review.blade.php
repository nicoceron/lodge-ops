@extends('direct-booking.layout')

@section('title', __('direct-booking.review.title'))

@section('content')
<div data-analytics-event="quote_viewed">
    @include('direct-booking._errors')
    <header class="page-intro">
        <p class="eyebrow">{{ __('direct-booking.review.eyebrow') }}</p>
        <h1>{{ __('direct-booking.review.title') }}</h1>
        <p class="lede">
            {{ $property['name'] }} ·
            <time datetime="{{ $quote['arrival_date'] }}">{{ \App\View\DirectBookingPresenter::date($quote['arrival_date'], $locale) }}</time>
            <span aria-hidden="true"> → </span>
            <time datetime="{{ $quote['departure_date'] }}">{{ \App\View\DirectBookingPresenter::date($quote['departure_date'], $locale) }}</time>
        </p>
        <div class="deadline" data-countdown="{{ $quote['quote_expires_at'] }}" data-warning-seconds="300">
            <strong>{{ __('direct-booking.review.expires', ['time' => \App\View\DirectBookingPresenter::dateTime($quote['quote_expires_at'], $quote['timezone'], $locale)]) }}</strong>
            <span aria-live="polite" data-countdown-output></span>
        </div>
        <div class="notice warning" data-timeout-warning hidden tabindex="-1">{{ __('direct-booking.review.timeout_warning') }}</div>
    </header>

    <div class="review-grid">
        <div class="review-main">
            <section class="booking-card" aria-labelledby="breakdown-title">
                <div class="section-heading compact"><span>01</span><div><h2 id="breakdown-title">{{ __('direct-booking.review.breakdown') }}</h2></div></div>
                <div class="price-table" role="table" aria-label="{{ __('direct-booking.review.breakdown') }}">
                    <div class="price-row"><div role="cell"><span class="line-kind">{{ __('direct-booking.review.nights') }}</span></div><strong role="cell">{{ __('direct-booking.review.nights_value', ['count' => collect($quote['lines'] ?? [])->where('type', 'nightly_rate')->count()]) }}</strong></div>
                    @foreach($quote['lines'] as $line)
                        <div class="price-row" role="row"><div role="cell"><span class="line-kind">{{ __('direct-booking.review.'.$line['type']) }}</span><strong>{{ $line['description'] }}</strong></div><div role="cell" class="amount">{{ \App\View\DirectBookingPresenter::money($line['amount'], $locale) }}</div></div>
                    @endforeach
                    <div class="price-row total" role="row"><strong role="cell">{{ __('direct-booking.review.total') }}</strong><strong role="cell" class="amount">{{ \App\View\DirectBookingPresenter::money($quote['total'], $locale) }}</strong></div>
                    <div class="deposit-row"><span>{{ __('direct-booking.review.deposit') }}</span><strong>{{ \App\View\DirectBookingPresenter::money($quote['deposit'], $locale) }}</strong></div>
                </div>
            </section>

            <form method="post" action="{{ route('direct-booking.hold', [$propertySlug, $orderReference]) }}" class="booking-form" data-disable-submit>
                @csrf
                <input type="hidden" name="expected_state_version" value="{{ $quote['state_version'] }}">
                <input type="hidden" name="hold_idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">

                <section class="booking-card" aria-labelledby="guest-title">
                    <div class="section-heading compact"><span>02</span><div><h2 id="guest-title">{{ __('direct-booking.review.guest_title') }}</h2></div></div>
                    <div class="form-grid two">
                        <label for="first_name">{{ __('direct-booking.review.first_name') }}<input id="first_name" name="first_name" required maxlength="100" autocomplete="given-name" @if(isset($bookingErrors) && $bookingErrors->has('first_name')) aria-invalid="true" @endif></label>
                        <label for="last_name">{{ __('direct-booking.review.last_name') }}<input id="last_name" name="last_name" maxlength="100" autocomplete="family-name"></label>
                        <label for="email">{{ __('direct-booking.review.email') }}<input id="email" type="email" name="email" required maxlength="254" autocomplete="email" inputmode="email" @if(isset($bookingErrors) && $bookingErrors->has('email')) aria-invalid="true" @endif></label>
                        <label for="phone">{{ __('direct-booking.review.phone') }}<input id="phone" type="tel" name="phone" maxlength="40" autocomplete="tel" inputmode="tel"></label>
                    </div>
                    <p class="notice neutral">{{ __('direct-booking.review.companions') }}</p>
                </section>

                <section class="booking-card policy-section" aria-labelledby="policies-title">
                    <div class="section-heading compact"><span>03</span><div><h2 id="policies-title">{{ __('direct-booking.review.policies') }}</h2></div></div>
                    @foreach(['terms','privacy','cancellation','no_show'] as $kind)
                        @php($policy = $policies[$kind] ?? ['title' => __('direct-booking.policy.'.$kind), 'body' => __('direct-booking.policy.unavailable')])
                        <details id="policy-{{ $kind }}"><summary>{{ __('direct-booking.policy.'.$kind) }} <span>v{{ data_get($policy, 'version', '—') }}</span></summary><div class="policy-body">{!! nl2br(e($policy['body'])) !!}</div></details>
                        <label class="check-row"><input type="checkbox" name="consent[{{ $kind }}]" value="1" required><span>{{ __('direct-booking.review.required_consent', ['policy' => __('direct-booking.policy.'.$kind)]) }}</span></label>
                    @endforeach
                    @if(isset($policies['marketing_consent']))
                        <details id="policy-marketing_consent"><summary>{{ __('direct-booking.policy.marketing_consent') }} <span>v{{ data_get($policies, 'marketing_consent.version', '—') }}</span></summary><div class="policy-body">{!! nl2br(e(data_get($policies, 'marketing_consent.body', __('direct-booking.policy.unavailable')))) !!}</div></details>
                        <input type="hidden" name="consent[marketing_consent]" value="0">
                        <label class="check-row optional"><input type="checkbox" name="consent[marketing_consent]" value="1"><span>{{ __('direct-booking.review.marketing_consent') }}</span></label>
                    @endif
                </section>

                <section class="booking-card final-action">
                    @include('direct-booking._turnstile')
                    <p>{{ __('direct-booking.review.hold_hint') }}</p>
                    <button class="button wide" type="submit" @disabled(!$policiesReady || (empty($turnstile['site_key']) && empty($turnstile['mock_token'])))>{{ __('direct-booking.review.hold') }}</button>
                </section>
            </form>
        </div>
        <aside class="booking-card stay-summary" aria-labelledby="stay-summary-title">
            <h2 id="stay-summary-title">{{ __('direct-booking.review.stay') }}</h2>
            <dl>
                <div><dt>{{ __('direct-booking.property.arrival') }}</dt><dd><time datetime="{{ $quote['arrival_date'] }}">{{ \App\View\DirectBookingPresenter::date($quote['arrival_date'], $locale) }}</time></dd></div>
                <div><dt>{{ __('direct-booking.property.departure') }}</dt><dd><time datetime="{{ $quote['departure_date'] }}">{{ \App\View\DirectBookingPresenter::date($quote['departure_date'], $locale) }}</time></dd></div>
                <div><dt>{{ __('direct-booking.property.adults') }}</dt><dd>{{ $search['adults'] ?? '—' }}</dd></div>
                <div><dt>{{ __('direct-booking.review.deposit') }}</dt><dd>{{ \App\View\DirectBookingPresenter::money($quote['deposit'], $locale) }}</dd></div>
            </dl>
        </aside>
    </div>
</div>
@endsection
