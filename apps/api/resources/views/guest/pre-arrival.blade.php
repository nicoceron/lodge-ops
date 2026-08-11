@extends('guest.layout')

@section('title', 'Pre-arrival details')

@section('content')
<div class="stack">
    <section><h1>Pre-arrival details</h1><p class="lede">Contact, travel and preference details help the lodge prepare your stay.</p></section>
    <form class="card stack" method="post" action="{{ route('guest.portal.pre-arrival.update') }}">
        @csrf @method('PUT')
        <h2>Guest profile</h2>
        <div class="grid two">
            <label>Preferred name<input name="profile[preferred_name]" required maxlength="100" value="{{ old('profile.preferred_name', data_get($pre_arrival, 'profile.preferred_name', $reservation['guest']['preferred_name'])) }}"></label>
            <label>Email<input type="email" name="profile[email]" required maxlength="255" value="{{ old('profile.email', data_get($pre_arrival, 'profile.email', $reservation['guest']['email'])) }}"></label>
            <label>Mobile<input name="profile[mobile]" required maxlength="40" value="{{ old('profile.mobile', data_get($pre_arrival, 'profile.mobile', $reservation['guest']['mobile'])) }}"></label>
            <label>Emergency contact name<input name="profile[emergency_name]" required maxlength="150" value="{{ old('profile.emergency_name', data_get($pre_arrival, 'profile.emergency_name')) }}"></label>
            <label>Emergency contact phone<input name="profile[emergency_phone]" required maxlength="40" value="{{ old('profile.emergency_phone', data_get($pre_arrival, 'profile.emergency_phone')) }}"></label>
        </div>
        <h2>Travel</h2>
        <div class="grid two">
            <label>Arrival method<select name="travel[arrival_method]" required>@foreach(['flight'=>'Flight','car'=>'Car','other'=>'Other'] as $value=>$label)<option value="{{ $value }}" @selected(old('travel.arrival_method', data_get($pre_arrival, 'travel.arrival_method')) === $value)>{{ $label }}</option>@endforeach</select></label>
            <label>Arrival reference<input name="travel[arrival_reference]" maxlength="100" value="{{ old('travel.arrival_reference', data_get($pre_arrival, 'travel.arrival_reference')) }}"></label>
            <label>Arrival time<input type="datetime-local" name="travel[arrival_time]" required value="{{ old('travel.arrival_time', data_get($pre_arrival, 'travel.arrival_time') ? \Carbon\CarbonImmutable::parse(data_get($pre_arrival, 'travel.arrival_time'))->format('Y-m-d\TH:i') : '') }}"></label>
            <label>Departure reference<input name="travel[departure_reference]" required maxlength="100" value="{{ old('travel.departure_reference', data_get($pre_arrival, 'travel.departure_reference')) }}"></label>
            <label>Departure time<input type="datetime-local" name="travel[departure_time]" required value="{{ old('travel.departure_time', data_get($pre_arrival, 'travel.departure_time') ? \Carbon\CarbonImmutable::parse(data_get($pre_arrival, 'travel.departure_time'))->format('Y-m-d\TH:i') : '') }}"></label>
        </div>
        <h2>Preferences</h2>
        <div class="grid two">
            <label>Dietary style<input name="preferences[dietary_style]" required maxlength="100" value="{{ old('preferences.dietary_style', data_get($pre_arrival, 'preferences.dietary_style')) }}"></label>
            <label>Allergies<textarea name="preferences[allergies]" maxlength="1000">{{ old('preferences.allergies', data_get($pre_arrival, 'preferences.allergies')) }}</textarea></label>
            <label>Accessibility or mobility needs<textarea name="preferences[accessibility]" maxlength="2000">{{ old('preferences.accessibility', data_get($pre_arrival, 'preferences.accessibility')) }}</textarea></label>
        </div>
        <label><input type="checkbox" name="preferences[medical_consent]" value="1" required @checked(old('preferences.medical_consent', data_get($pre_arrival, 'preferences.medical_consent'))) >I consent to the lodge using these details to safely prepare my stay.</label>
        <div><button type="submit">Save pre-arrival details</button></div>
    </form>
</div>
@endsection
