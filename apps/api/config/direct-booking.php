<?php

return [
    'api_version' => '2026-08-20',
    'default_session_ttl_minutes' => 120,
    'recovery_ttl_minutes' => 10080,
    'initial_hold_minutes' => 30,
    'checkout_extension_minutes' => 15,
    'maximum_hold_minutes' => 45,
    'retention_days' => 30,
    'turnstile_timeout_seconds' => 5,
    'turnstile_secret' => env('TURNSTILE_SECRET'),
    'turnstile_allowed_hostnames' => array_values(array_filter(array_map('trim', explode(',', (string) env('TURNSTILE_ALLOWED_HOSTNAMES', ''))))),
    'rate_limits' => [
        'read_per_minute' => 60,
        'search_per_minute' => 20,
        'mutation_per_minute' => 10,
        'holds_per_hour' => 5,
    ],
    'allow_operational_fact_rollback' => (bool) env('ALLOW_DIRECT_BOOKING_FACT_ROLLBACK', false),
];
