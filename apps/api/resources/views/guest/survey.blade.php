@extends('guest.layout')

@section('title', 'Feedback')

@section('content')
<div class="stack">
    <section><h1>Feedback</h1><p class="lede">Tell the lodge team about your stay.</p></section>
    <section class="card stack">
        @if ($survey['submitted'])
            <div class="notice">Thank you. Your feedback has already been submitted.</div>
        @elseif (!$survey['available'])
            <p class="muted">Feedback opens after your departure.</p>
        @else
            <form class="stack" method="post" action="{{ route('guest.portal.survey.store') }}">
                @csrf
                <div class="grid two">
                    <label>Overall stay<select name="stay_rating" required><option value="">Choose</option>@foreach(range(5,1) as $rating)<option value="{{ $rating }}" @selected(old('stay_rating') == $rating)>{{ $rating }} / 5</option>@endforeach</select></label>
                    <label>Guide experience<select name="guide_rating" required><option value="">Choose</option>@foreach(range(5,1) as $rating)<option value="{{ $rating }}" @selected(old('guide_rating') == $rating)>{{ $rating }} / 5</option>@endforeach</select></label>
                </div>
                <label>Comments<textarea name="comment" maxlength="5000">{{ old('comment') }}</textarea></label>
                <input type="hidden" name="share_with_team" value="0">
                <label><input type="checkbox" name="share_with_team" value="1" @checked(old('share_with_team'))>Share my comments with the lodge team.</label>
                <div><button type="submit">Submit feedback</button></div>
            </form>
        @endif
    </section>
</div>
@endsection
