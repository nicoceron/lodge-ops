<?php

namespace Tests\Unit\Payments;

use App\Data\Payments\CheckoutRequest;
use App\Data\Payments\WebhookRequest;
use App\Integrations\Payments\MercadoPago\MercadoPagoCheckoutProGateway;
use App\Integrations\Payments\MercadoPago\MercadoPagoTransport;
use PHPUnit\Framework\TestCase;

class MercadoPagoGatewayContractTest extends TestCase
{
    public function test_preference_uses_exact_decimal_money_and_stable_idempotency(): void
    {
        $transport = new RecordingTransport(['id' => 'pref-1', 'sandbox_init_point' => 'https://sandbox.mercadopago.com/checkout/test']);
        $gateway = new MercadoPagoCheckoutProGateway($transport, 'webhook-secret', 'seller-1');
        $checkout = $gateway->createHostedCheckout(new CheckoutRequest('external-1', 'idem-1', 12_345, 'ARS', 'Deposit', 'https://inn.test/return', 'https://inn.test/return', 'https://inn.test/return', 'https://inn.test/webhook'));

        $this->assertSame('pref-1', $checkout->preferenceId);
        $this->assertSame('123.45', data_get($transport->payload, 'items.0.unit_price'));
        $this->assertSame('idem-1', $transport->headers['X-Idempotency-Key']);
    }

    public function test_webhook_signature_is_verified_before_json_is_exposed(): void
    {
        $secret = 'webhook-secret';
        $gateway = new MercadoPagoCheckoutProGateway(new RecordingTransport([]), $secret, 'seller-1');
        $timestamp = (string) time();
        $manifest = "id:123;request-id:req-1;ts:{$timestamp};";
        $signature = hash_hmac('sha256', $manifest, $secret);
        $event = $gateway->verifyWebhook(new WebhookRequest(
            '{"type":"payment","action":"payment.updated","data":{"id":"123"}}',
            ['x-signature' => "ts={$timestamp},v1={$signature}", 'x-request-id' => 'req-1'],
            ['data.id' => '123'],
        ));

        $this->assertSame('123', $event->resourceId);
        $this->expectException(\RuntimeException::class);
        $gateway->verifyWebhook(new WebhookRequest('{}', ['x-signature' => "ts={$timestamp},v1=bad", 'x-request-id' => 'req-1'], ['data.id' => '123']));
    }
}

final class RecordingTransport implements MercadoPagoTransport
{
    public array $payload = [];

    public array $headers = [];

    public function __construct(private readonly array $response) {}

    public function request(string $method, string $path, array $payload = [], array $headers = []): array
    {
        $this->payload = $payload;
        $this->headers = $headers;

        return $this->response;
    }
}
