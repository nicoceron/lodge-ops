@php
    $bookingError = session('booking_error');
    $messages = isset($bookingErrors) ? $bookingErrors->all() : [];
    if (is_string($bookingError)) {
        array_unshift($messages, __("direct-booking.errors.{$bookingError}"));
    }
@endphp
@if($messages !== [])
    <section class="error-summary" role="alert" aria-labelledby="booking-errors-title" tabindex="-1" data-error-summary>
        <h2 id="booking-errors-title">{{ __('direct-booking.errors.heading') }}</h2>
        <ul>
            @foreach($messages as $message)<li>{{ $message }}</li>@endforeach
        </ul>
    </section>
@endif
