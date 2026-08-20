<?php

return [
    'evidence_scanner_available' => env('PAYMENT_EVIDENCE_SCANNER_AVAILABLE', true),
    'cash_shift_stale_hours' => (int) env('CASH_SHIFT_STALE_HOURS', 16),
];
