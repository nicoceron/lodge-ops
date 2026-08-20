<?php

return [
    'evidence_scanner_available' => env('PAYMENT_EVIDENCE_SCANNER_AVAILABLE', false),
    'evidence_pdf_parser_binary' => env('PAYMENT_EVIDENCE_PDF_PARSER_BINARY', 'pdfinfo'),
    'cash_shift_stale_hours' => (int) env('CASH_SHIFT_STALE_HOURS', 16),
    'cash_variance_review_threshold_minor' => (int) env('CASH_VARIANCE_REVIEW_THRESHOLD_MINOR', 0),
    'idempotency_pending_lease_seconds' => max(5, (int) env('IDEMPOTENCY_PENDING_LEASE_SECONDS', 30)),
    'allow_operational_fact_rollback' => (bool) env('ALLOW_P3_06B_OPERATIONAL_FACT_ROLLBACK', false),
];
