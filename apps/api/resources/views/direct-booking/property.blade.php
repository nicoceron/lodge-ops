@extends('direct-booking.layout')

@section('title', $property['name'] ?? __('direct-booking.brand'))
@section('robots', 'index,follow')

@section('content')
<div data-analytics-event="booking_viewed">
    @include('direct-booking._errors')
    @if(request()->boolean('recovered'))<div class="notice success" role="status">{{ __('direct-booking.property.recovered') }}</div>@endif

    <section class="booking-hero">
        <div class="booking-hero-copy">
            <p class="eyebrow">{{ __('direct-booking.property.eyebrow') }}</p>
            <h1>{{ __('direct-booking.property.title', ['property' => $property['name']]) }}</h1>
            <p class="lede">{{ $property['summary'] ?: __('direct-booking.property.summary_fallback') }}</p>
            <p class="timezone">{{ $property['timezone'] }}</p>
        </div>
        @if(!empty($property['media'][0]))
            <figure class="published-media hero-media" role="img" aria-label="{{ $property['media'][0]['alt'] }}" data-media-key="{{ $property['media'][0]['key'] }}">
                <span aria-hidden="true">Rincón<br>Grande</span>
                <figcaption class="sr-only">{{ $property['media'][0]['alt'] }}</figcaption>
            </figure>
        @endif
    </section>

    <section class="booking-card search-card" aria-labelledby="search-title">
        <div class="section-heading"><span>01</span><div><h2 id="search-title">{{ __('direct-booking.property.search_title') }}</h2><p>{{ __('direct-booking.property.results_hint') }}</p></div></div>
        <form method="get" action="{{ route('direct-booking.show', $propertySlug) }}" class="booking-form" data-booking-search>
            <input type="hidden" name="lang" value="{{ str_starts_with($locale, 'es') ? 'es' : 'en' }}">
            <div class="form-grid five">
                <label for="arrival_date">{{ __('direct-booking.property.arrival') }}<input id="arrival_date" type="date" name="arrival_date" required min="{{ now()->format('Y-m-d') }}" value="{{ $search['arrival_date'] }}" @if($bookingErrors->has('arrival_date')) aria-invalid="true" @endif></label>
                <label for="departure_date">{{ __('direct-booking.property.departure') }}<input id="departure_date" type="date" name="departure_date" required min="{{ now()->addDay()->format('Y-m-d') }}" value="{{ $search['departure_date'] }}" @if($bookingErrors->has('departure_date')) aria-invalid="true" @endif></label>
                <label for="adults">{{ __('direct-booking.property.adults') }}<input id="adults" type="number" name="adults" min="1" max="50" required inputmode="numeric" value="{{ $search['adults'] }}"></label>
                <label for="children">{{ __('direct-booking.property.children') }}<input id="children" type="number" name="children" min="0" max="50" required inputmode="numeric" value="{{ $search['children'] }}"></label>
                <label for="infants">{{ __('direct-booking.property.infants') }}<input id="infants" type="number" name="infants" min="0" max="20" required inputmode="numeric" value="{{ $search['infants'] }}"></label>
            </div>
            <div class="form-actions">
                <label class="currency-field" for="currency">{{ __('direct-booking.property.currency') }}<select id="currency" name="currency">@foreach($property['supported_currencies'] as $currency)<option value="{{ $currency }}" @selected($search['currency'] === $currency)>{{ $currency }}</option>@endforeach</select></label>
                @if(collect($property['bookables'] ?? [])->contains('kind', 'program'))
                    <label class="program-field" for="program_key">{{ __('direct-booking.property.program') }}<select id="program_key" name="program_key"><option value="">{{ __('direct-booking.property.all_programs') }}</option>@foreach($property['bookables'] as $item)@if(($item['kind'] ?? null) === 'program')<option value="{{ $item['key'] }}" @selected(($search['program_key'] ?? null) === $item['key'])>{{ $item['name'] }}</option>@endif @endforeach</select></label>
                @endif
                <button class="button" type="submit">{{ __('direct-booking.property.search') }}</button>
            </div>
        </form>
    </section>

    @if($searchAttempted && $availability)
        @php
            $bookability = collect($availability['options'] ?? [])->keyBy('key');
            $availableCount = collect($property['bookables'])->filter(fn($item) => (bool) data_get($bookability->get($item['key']), 'bookable'))->count();
        @endphp
        <section class="results-section" aria-labelledby="results-title" data-analytics-event="availability_searched">
            <div class="section-heading"><span>02</span><div><h2 id="results-title">{{ __('direct-booking.property.results') }}</h2><p><time datetime="{{ $availability['arrival_date'] }}">{{ \App\View\DirectBookingPresenter::date($availability['arrival_date'], $locale) }}</time> <span aria-hidden="true">—</span> <time datetime="{{ $availability['departure_date'] }}">{{ \App\View\DirectBookingPresenter::date($availability['departure_date'], $locale) }}</time></p></div></div>
            @if($availableCount === 0)
                <div class="booking-card empty-state"><h3>{{ __('direct-booking.property.empty_title') }}</h3><p>{{ __('direct-booking.property.empty_body') }}</p><a class="button secondary" href="#search-title">{{ __('direct-booking.property.another_date') }}</a></div>
            @else
                <form method="post" action="{{ route('direct-booking.quote', $propertySlug) }}" class="booking-form booking-card" data-disable-submit>
                    @csrf
                    @foreach(['arrival_date','departure_date','adults','children','infants','currency','locale','ui_locale','program_key'] as $field)<input type="hidden" name="{{ $field }}" value="{{ $search[$field] ?? '' }}">@endforeach
                    @foreach($attribution as $key => $value)<input type="hidden" name="attribution[{{ $key }}]" value="{{ $value }}">@endforeach
                    <input type="hidden" name="begin_idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <input type="hidden" name="quote_idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <div class="option-grid">
                        @foreach($property['bookables'] as $item)
                            @php($bookable = (bool) data_get($bookability->get($item['key']), 'bookable'))
                            <label class="option-card @if(!$bookable) unavailable @endif">
                                <input type="radio" name="option_key" value="{{ $item['key'] }}" @disabled(!$bookable) required>
                                @if(!empty($item['media'][0]))<span class="option-visual" role="img" aria-label="{{ $item['media'][0]['alt'] }}" data-media-key="{{ $item['media'][0]['key'] }}"></span>@endif
                                <span class="option-copy"><span class="pill">{{ $bookable ? __('direct-booking.property.available') : __('direct-booking.property.unavailable') }}</span><strong>{{ $item['name'] }}</strong><span>{{ $item['summary'] }}</span></span>
                            </label>
                        @endforeach
                    </div>
                    <label for="voucher_code">{{ __('direct-booking.property.voucher') }}<input id="voucher_code" name="voucher_code" minlength="4" maxlength="80" autocomplete="off" aria-describedby="voucher-hint"><span id="voucher-hint" class="field-hint">{{ __('direct-booking.property.voucher_hint') }}</span></label>
                    @include('direct-booking._turnstile')
                    <div class="form-actions end"><button class="button" type="submit" @disabled(empty($turnstile['site_key']) && empty($turnstile['mock_token']))>{{ __('direct-booking.property.continue') }}</button></div>
                </form>
            @endif
        </section>
    @endif

    <section class="published-options" aria-label="{{ __('direct-booking.property.results') }}">
        @foreach($property['bookables'] as $item)
            <article><span>{{ strtoupper($item['kind']) }}</span><h2>{{ $item['name'] }}</h2><p>{{ $item['summary'] }}</p></article>
        @endforeach
    </section>
</div>
@endsection
