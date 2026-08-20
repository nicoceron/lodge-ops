<?php

declare(strict_types=1);

$fixtures = __DIR__.'/fixtures';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$state = $_GET['fixture_state'] ?? null;

header('Content-Type: application/json');
header('X-Correlation-ID: direct-booking-mock-0001');

if ($state !== null) {
    header('Cache-Control: no-store, private');
    $catalog = json_decode((string) file_get_contents($fixtures.'/order-states.json'), true, flags: JSON_THROW_ON_ERROR);
    if (! isset($catalog[$state])) {
        http_response_code(404);
        readfile($fixtures.'/error-not-found.json');
        return;
    }
    echo json_encode($catalog[$state], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
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
    header('Cache-Control: no-store, private');
    http_response_code(404);
    readfile($fixtures.'/error-not-found.json');
    return;
}

$published = in_array($fixture, ['property.json', 'policy.json'], true);
header('Cache-Control: '.($published ? 'public, max-age=60, stale-while-revalidate=300' : 'no-store, private'));
if ($published) {
    header('Content-Language: '.($_GET['locale'] ?? 'es-AR'));
}
readfile($fixtures.'/'.$fixture);
