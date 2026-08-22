<!doctype html>
<html lang="{{ str_starts_with($locale ?? app()->getLocale(), 'es') ? 'es' : 'en' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <meta name="robots" content="@yield('robots', 'noindex,nofollow,noarchive')">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('direct-booking.brand')) · Inn</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @if(!empty($turnstile['site_key']))
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endif
</head>
<body class="booking-body" data-booking-app data-locale="{{ $locale ?? app()->getLocale() }}" data-analytics-url="{{ route('direct-booking.analytics', $propertySlug) }}">
<a class="booking-skip" href="#booking-main">{{ __('direct-booking.skip') }}</a>
<header class="booking-header">
    <div class="booking-shell booking-header-inner">
        <a class="booking-brand" href="{{ route('direct-booking.show', $propertySlug) }}" aria-label="{{ __('direct-booking.brand') }}">
            <span aria-hidden="true">I</span>
            <strong>Inn</strong>
        </a>
        <nav class="booking-language" aria-label="{{ __('direct-booking.language') }}">
            @php
                $languageOrderReference = $orderReference ?? request()->route('orderReference');
                $languageRoute = $languageOrderReference !== null && request()->routeIs('direct-booking.review')
                    ? 'direct-booking.review'
                    : ($languageOrderReference !== null && request()->routeIs('direct-booking.status') ? 'direct-booking.status' : 'direct-booking.show');
                $languageParameters = $languageOrderReference !== null ? [$propertySlug, $languageOrderReference] : [$propertySlug];
            @endphp
            <a href="{{ route($languageRoute, array_merge($languageParameters, ['lang' => 'en'])) }}" @if(!str_starts_with($locale ?? '', 'es')) aria-current="page" @endif lang="en">{{ __('direct-booking.english') }}</a>
            <a href="{{ route($languageRoute, array_merge($languageParameters, ['lang' => 'es'])) }}" @if(str_starts_with($locale ?? '', 'es')) aria-current="page" @endif lang="es">{{ __('direct-booking.spanish') }}</a>
        </nav>
    </div>
</header>
<main id="booking-main" class="booking-shell booking-main" tabindex="-1">
    @yield('content')
</main>
<footer class="booking-footer">
    <div class="booking-shell booking-footer-grid">
        <div><strong>{{ __('direct-booking.secure_note') }}</strong></div>
        @if(!empty($property['accessible_fallback_url']))
            <div class="booking-help"><span>{{ __('direct-booking.help') }}</span><a href="{{ $property['accessible_fallback_url'] }}">{{ __('direct-booking.fallback') }}</a></div>
        @endif
    </div>
    <div class="booking-shell analytics-consent" data-analytics-consent hidden>
        <div><strong>{{ __('direct-booking.analytics.title') }}</strong><p>{{ __('direct-booking.analytics.body') }}</p></div>
        <div class="button-row"><button type="button" class="button secondary" data-analytics-choice="declined">{{ __('direct-booking.analytics.decline') }}</button><button type="button" class="button" data-analytics-choice="accepted">{{ __('direct-booking.analytics.accept') }}</button></div>
    </div>
</footer>
<div class="sr-only" aria-live="polite" aria-atomic="true" data-global-live></div>
</body>
</html>
