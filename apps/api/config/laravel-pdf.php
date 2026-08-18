<?php

return [
    'driver' => env('DOCUMENT_RENDERER', 'dompdf'),

    'dompdf' => [
        'is_remote_enabled' => false,
        'chroot' => implode(',', [
            resource_path('views/documents'),
            storage_path('app/private/document-assets'),
        ]),
    ],
];
