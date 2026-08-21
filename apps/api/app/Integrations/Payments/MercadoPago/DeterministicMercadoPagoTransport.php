<?php

namespace App\Integrations\Payments\MercadoPago;

use App\Data\Payments\ExactJsonDecimal;
use RuntimeException;

/**
 * Local-only provider simulator used to prove the real HTTP/queue worker topology.
 */
final class DeterministicMercadoPagoTransport implements MercadoPagoTransport
{
    /** @param array<string, mixed> $fixture */
    public function __construct(
        private readonly array $fixture,
        private readonly ?string $providerAccount = null,
    ) {}

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
        if (strtoupper($method) === 'GET' && $path === '/terminals/v1/list') {
            return ['terminals' => (array) data_get($this->fixture, 'terminals', [])];
        }
        if (strtoupper($method) === 'POST' && $path === '/v1/orders') {
            $order = data_get($this->fixture, 'order');
            if (is_array($order)) {
                return $order;
            }
            if (data_get($this->fixture, 'orders_virtual') === true) {
                $type = (string) data_get($payload, 'type');
                $reference = (string) data_get($payload, 'external_reference');
                $amount = data_get($payload, 'transactions.payments.0.amount');
                $major = $amount instanceof ExactJsonDecimal ? $amount->value : (string) $amount;
                $suffix = strtoupper(substr(hash('sha256', $type.':'.$reference), 0, 26));

                return [
                    'id' => 'ORD'.$suffix,
                    'type' => $type,
                    'user_id' => $this->providerAccount ?? (string) data_get($this->fixture, 'provider_account', 'seller-orders-compose-uat'),
                    'external_reference' => $reference,
                    'status' => 'created',
                    'status_detail' => 'created',
                    'currency' => (string) data_get($this->fixture, 'currency', 'ARS'),
                    'total_amount' => $major,
                    'config' => (array) data_get($payload, 'config', []),
                    'transactions' => ['payments' => [[
                        'id' => 'PAY'.$suffix,
                        'amount' => $major,
                        'paid_amount' => '0.00',
                        'status' => 'created',
                        'status_detail' => 'created',
                    ]]],
                    'type_response' => $type === 'qr' ? ['qr_data' => '000201010212INN-COMPOSE-UAT-QR-'.$suffix] : [],
                ];
            }
        }
        if (strtoupper($method) === 'GET' && str_starts_with($path, '/v1/orders/')) {
            $order = data_get($this->fixture, 'order');
            if (is_array($order) && (string) data_get($order, 'id') === basename($path)) {
                return $order;
            }
        }
        if (strtoupper($method) === 'POST' && str_ends_with($path, '/cancel')) {
            $order = data_get($this->fixture, 'canceled_order');
            if (is_array($order)) {
                return $order;
            }
        }
        if (strtoupper($method) === 'POST' && str_ends_with($path, '/refund')) {
            $refund = data_get($this->fixture, 'refunded_order');
            if (is_array($refund)) {
                return $refund;
            }
        }

        throw new RuntimeException('The deterministic Mercado Pago fixture has no response for this provider request.');
    }
}
