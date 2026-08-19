<?php

namespace Tests\Unit\Payments;

use App\Data\Payments\CheckoutRequest;
use App\Data\Payments\WebhookRequest;
use App\Integrations\Payments\MercadoPago\MercadoPagoCheckoutProGateway;
use App\Integrations\Payments\MercadoPago\MercadoPagoTransport;
use PHPUnit\Framework\TestCase;

class MercadoPagoGatewayContractTest extends TestCase
{
    public function test_preference_uses_numeric_decimal_money_and_stable_idempotency(): void
    {
        $transport = new RecordingTransport(['id' => 'pref-1', 'sandbox_init_point' => 'https://sandbox.mercadopago.com/checkout/test']);
        $gateway = new MercadoPagoCheckoutProGateway($transport, 'webhook-secret', 'seller-1');
        $checkout = $gateway->createHostedCheckout(new CheckoutRequest('external-1', 'idem-1', 12_345, 'ARS', 'Deposit', 'https://inn.test/return', 'https://inn.test/return', 'https://inn.test/return', 'https://inn.test/webhook'));

        $this->assertSame('pref-1', $checkout->preferenceId);
        $this->assertSame(123.45, data_get($transport->payload, 'items.0.unit_price'));
        $this->assertSame('idem-1', $transport->headers['X-Idempotency-Key']);
    }

    public function test_colombia_checkout_host_is_allowed(): void
    {
        $transport = new RecordingTransport([
            'id' => 'pref-co-1',
            'sandbox_init_point' => 'https://sandbox.mercadopago.com.co/checkout/v1/redirect?pref_id=pref-co-1',
        ]);
        $gateway = new MercadoPagoCheckoutProGateway($transport, 'webhook-secret', 'seller-1');

        $checkout = $gateway->createHostedCheckout(new CheckoutRequest('external-co-1', 'idem-co-1', 1_000_000, 'COP', 'Deposit', 'https://inn.test/return', 'https://inn.test/return', 'https://inn.test/return', 'https://inn.test/webhook'));

        $this->assertSame('pref-co-1', $checkout->preferenceId);
    }

    public function test_test_user_connection_can_choose_normal_checkout_url_without_being_labeled_production(): void
    {
        $transport = new RecordingTransport([
            'id' => 'pref-test-user-1',
            'sandbox_init_point' => 'https://sandbox.mercadopago.com.co/checkout/test',
            'init_point' => 'https://www.mercadopago.com.co/checkout/v1/redirect?pref_id=pref-test-user-1',
        ]);
        $gateway = new MercadoPagoCheckoutProGateway($transport, 'webhook-secret', 'seller-1', 'sandbox', false);

        $checkout = $gateway->createHostedCheckout(new CheckoutRequest('external-test-user-1', 'idem-test-user-1', 1_000_000, 'COP', 'Deposit', 'https://inn.test/return', 'https://inn.test/return', 'https://inn.test/return', 'https://inn.test/webhook'));

        $this->assertSame('https://www.mercadopago.com.co/checkout/v1/redirect?pref_id=pref-test-user-1', $checkout->url);
    }

    public function test_refund_lookup_derives_currency_from_the_payment_when_provider_omits_it(): void
    {
        $gateway = new MercadoPagoCheckoutProGateway(new RefundLookupTransport, 'webhook-secret', 'seller-1');

        $refund = $gateway->fetchRefund('payment-1', 'refund-1');

        $this->assertSame('COP', $refund->currency);
        $this->assertSame(200_000, $refund->amountMinor);
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

final class RefundLookupTransport implements MercadoPagoTransport
{
    public function request(string $method, string $path, array $payload = [], array $headers = []): array
    {
        if (str_ends_with($path, '/refunds/refund-1')) {
            return ['id' => 'refund-1', 'payment_id' => 'payment-1', 'status' => 'approved', 'amount' => 2000];
        }

        return [
            'id' => 'payment-1',
            'external_reference' => 'external-1',
            'status' => 'approved',
            'transaction_amount' => 10000,
            'currency_id' => 'COP',
        ];
    }
}
