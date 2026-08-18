<?php

return [
    'disk' => env('DOCUMENT_DISK', 'documents'),
    'renderer' => env('DOCUMENT_RENDERER', 'dompdf'),
    'pdfinfo_binary' => env('DOCUMENT_PDFINFO_BINARY', 'pdfinfo'),

    'exports' => [
        'ttl_days' => (int) env('DOCUMENT_EXPORT_TTL_DAYS', 7),
    ],

    'assets' => [
        'roots' => [
            resource_path('views/documents'),
            storage_path('app/private/document-assets'),
        ],
        'remote_enabled' => false,
    ],

    'jobs' => [
        'documents' => [
            'queue' => env('DOCUMENT_JOB_QUEUE', 'documents'),
            'timeout' => (int) env('DOCUMENT_JOB_TIMEOUT', 90),
            'tries' => (int) env('DOCUMENT_JOB_TRIES', 3),
            'backoff' => [10, 30, 60],
            'retry_for_minutes' => (int) env('DOCUMENT_JOB_RETRY_FOR_MINUTES', 15),
            'overlap_expire_after' => (int) env('DOCUMENT_JOB_OVERLAP_EXPIRE_AFTER', 240),
        ],
        'exports' => [
            'queue' => env('DOCUMENT_EXPORT_JOB_QUEUE', 'reports'),
            'timeout' => (int) env('DOCUMENT_EXPORT_JOB_TIMEOUT', 180),
            'tries' => (int) env('DOCUMENT_EXPORT_JOB_TRIES', 3),
            'backoff' => [15, 60, 180],
            'retry_for_minutes' => (int) env('DOCUMENT_EXPORT_JOB_RETRY_FOR_MINUTES', 30),
            'overlap_expire_after' => (int) env('DOCUMENT_EXPORT_JOB_OVERLAP_EXPIRE_AFTER', 300),
        ],
    ],
];
