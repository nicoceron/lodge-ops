@extends('direct-booking.layout')

@section('title', __('direct-booking.unavailable.title'))

@section('content')
<section class="booking-card unavailable-page">
    <p class="eyebrow">Inn</p>
    <h1>{{ __('direct-booking.unavailable.title') }}</h1>
    <p class="lede">{{ __('direct-booking.errors.'.$errorCode) }}</p>
    <p>{{ __('direct-booking.unavailable.body') }}</p>
    @if($correlationId)<p class="support-reference">{{ __('direct-booking.unavailable.reference', ['reference' => $correlationId]) }}</p>@endif
    <a class="button" href="{{ route('direct-booking.show', $propertySlug) }}">{{ __('direct-booking.unavailable.try') }}</a>
</section>
@endsection
