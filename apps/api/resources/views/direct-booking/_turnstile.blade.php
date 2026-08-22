@if(!empty($turnstile['site_key']))
    <div class="cf-turnstile" data-sitekey="{{ $turnstile['site_key'] }}" data-action="{{ $turnstile['action'] }}" data-response-field-name="turnstile_token"></div>
@elseif(!empty($turnstile['mock_token']))
    <input type="hidden" name="turnstile_token" value="{{ $turnstile['mock_token'] }}">
@else
    <input type="hidden" name="turnstile_token" value="bot-verification-not-required">
@endif
