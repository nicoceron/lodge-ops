<?php

declare(strict_types=1);

$fixtures = __DIR__.'/fixtures';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$state = $_GET['fixture_state'] ?? null;

header('Content-Type: application/json');
header('Cache-Control: no-store, private');
header('X-Correlation-ID: direct-booking-mock-0001');

if ($state !== null) {
    $catalog = json_decode((string) file_get_contents($fixtures.'/order-states.json'), true, flags: JSON_THROW_ON_ERROR);
    if (! isset($catalog[$state])) {
        http_response_code(404);
        readfile($fixtures.'/error-not-found.json');
        return;
    }
    echo json_encode(['data' => array_merge([
        'order_reference' => '01K3A6S2V4T8N9R7W1X0Y3Z5QM',
        'state' => $state,
        'expires_at' => '2026-09-01T12:45:00Z',
        'payment_capabilities' => [['method' => 'manual_bank_transfer', 'currency' => 'USD']],
        'safe_failure_code' => null,
    ], $catalog[$state])], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    return;
}

$fixture = match (true) {
    $method === 'GET' && preg_match('#/policies/[a-z_]+$#', (string) $path) === 1 => 'policy.json',
    $method === 'POST' && str_ends_with((string) $path, '/availability') => 'availability.json',
    $method === 'POST' && str_ends_with((string) $path, '/quote') => 'quote.json',
    $method === 'POST' && str_ends_with((string) $path, '/hold') => 'order-held.json',
    $method === 'POST' && (str_ends_with((string) $path, '/checkout') || str_ends_with((string) $path, '/payments/retry')) => 'checkout.json',
    $method === 'POST' && str_ends_with((string) $path, '/manual-payment-evidence') => 'evidence-pending.json',
    $method === 'POST' && str_ends_with((string) $path, '/recover') => 'order-begun.json',
    $method === 'GET' && str_ends_with((string) $path, '/confirmation') => 'confirmation.json',
    $method === 'POST' && preg_match('#/orders$#', (string) $path) === 1 => 'order-begun.json',
    $method === 'GET' && preg_match('#/orders/[0-9A-HJKMNP-TV-Z]{26}$#', (string) $path) === 1 => 'order-held.json',
    $method === 'GET' && preg_match('#/properties/[a-z0-9-]+$#', (string) $path) === 1 => 'property.json',
    default => null,
};

if ($fixture === null) {
    http_response_code(404);
    readfile($fixtures.'/error-not-found.json');
    return;
}

readfile($fixtures.'/'.$fixture);
