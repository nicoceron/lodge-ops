<?php

return [
    'api_base_url' => env('DIRECT_BOOKING_UI_API_BASE_URL'),
    'allow_fixture_controls' => (bool) env('DIRECT_BOOKING_UI_ALLOW_FIXTURES', false),
    'turnstile_site_key' => env('TURNSTILE_SITE_KEY'),
    'contract_mock_turnstile_token' => env('DIRECT_BOOKING_UI_MOCK_TURNSTILE_TOKEN'),
    'analytics_events' => [
        'booking_viewed',
        'availability_searched',
        'quote_viewed',
        'checkout_selected',
        'status_viewed',
        'confirmation_viewed',
    ],
];
