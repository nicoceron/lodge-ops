<?php

namespace App\Integrations\Payments\MercadoPago;

use RuntimeException;

/**
 * Local-only provider simulator used to prove the real HTTP/queue worker topology.
 */
final class DeterministicMercadoPagoTransport implements MercadoPagoTransport
{
    /** @param array<string, mixed> $fixture */
    public function __construct(private readonly array $fixture) {}

    public function request(string $method, string $path, array $payload = [], array $headers = []): array
    {
        if (strtoupper($method) === 'POST' && $path === '/checkout/preferences') {
            return [
                'id' => (string) data_get($this->fixture, 'preference_id', 'pref-compose-uat'),
                'sandbox_init_point' => 'https://sandbox.mercadopago.com/checkout/v1/redirect?pref_id=compose-uat',
            ];
        }
        if (strtoupper($method) === 'GET' && str_starts_with($path, '/v1/payments/')) {
            $payment = data_get($this->fixture, 'payment');
            if (is_array($payment) && (string) data_get($payment, 'id') === basename($path)) {
                return $payment;
            }
        }
        if (strtoupper($method) === 'GET' && str_contains($path, '/refunds/')) {
            $refund = data_get($this->fixture, 'refund');
            if (is_array($refund) && (string) data_get($refund, 'id') === basename($path)) {
                return $refund;
            }
        }

        throw new RuntimeException('The deterministic Mercado Pago fixture has no response for this provider request.');
    }
}
