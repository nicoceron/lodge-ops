<?php

return [
    'fallback' => [
        'enabled' => env('COMMUNICATION_LOCAL_FALLBACK', env('APP_ENV') !== 'production'),
        'provider' => 'laravel-mail',
        'from_email' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'from_name' => env('MAIL_FROM_NAME', env('APP_NAME', 'Inn')),
    ],
    'provider' => [
        'timeout_seconds' => (int) env('COMMUNICATION_PROVIDER_TIMEOUT', 20),
        'idempotency_window_hours' => (int) env('COMMUNICATION_PROVIDER_IDEMPOTENCY_WINDOW_HOURS', 24),
        'event_queue' => env('COMMUNICATION_PROVIDER_EVENT_QUEUE', 'provider-events'),
        'notification_queue' => env('COMMUNICATION_NOTIFICATION_QUEUE', 'notifications'),
        'signature_tolerance_seconds' => (int) env('COMMUNICATION_WEBHOOK_TOLERANCE_SECONDS', 300),
    ],
    'milestones' => [
        'rule_version' => env('COMMUNICATION_MILESTONE_RULE_VERSION', '2026-08-20-v1'),
        'policy_version' => env('COMMUNICATION_MILESTONE_POLICY_VERSION', '2026-08-20-v1'),
        'claim_stale_minutes' => (int) env('COMMUNICATION_MILESTONE_CLAIM_STALE_MINUTES', 10),
    ],
];
