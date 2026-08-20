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
        $this->assertSame('123.45', (string) data_get($transport->payload, 'items.0.unit_price'));
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

    public function test_payment_account_and_nullable_settlement_facts_come_from_the_provider_resource(): void
    {
        $transport = new RecordingTransport([
            'id' => 123,
            'collector_id' => 456,
            'external_reference' => 'external-1',
            'status' => 'approved',
            'status_detail' => 'accredited',
            'transaction_amount' => '100.00',
            'currency_id' => 'ARS',
            'fee_details' => [['amount' => '2.00']],
            'transaction_details' => ['net_received_amount' => '98.00'],
        ]);
        $payment = (new MercadoPagoCheckoutProGateway($transport, 'secret', 'configured-account'))->fetchPayment('123');

        $this->assertSame('456', $payment->providerAccount);
        $this->assertSame(10_000, $payment->settlement['gross_minor']);
        $this->assertSame(200, $payment->settlement['fee_minor']);
        $this->assertNull($payment->settlement['tax_minor']);
        $this->assertNull($payment->settlement['payout_identity']);
    }

    public function test_provider_fee_and_charge_detail_decimals_are_summed_without_binary_floats(): void
    {
        $payment = (new MercadoPagoCheckoutProGateway(new RecordingTransport([
            'id' => 124,
            'collector_id' => 456,
            'external_reference' => 'external-exact-sums',
            'status' => 'approved',
            'transaction_amount' => '1.00',
            'currency_id' => 'ARS',
            'fee_details' => [['amount' => '0.10'], ['amount' => '0.20']],
            'charges_details' => [
                ['name' => 'tax withholding', 'amount' => '0.05'],
                ['name' => 'withholding adjustment', 'amount' => '0.06'],
            ],
        ]), 'secret', 'configured-account'))->fetchPayment('124');

        $this->assertSame(30, $payment->settlement['fee_minor']);
        $this->assertSame(11, $payment->settlement['withholding_minor']);
        $this->assertSame(70, $payment->settlement['net_minor']);
    }

    public function test_payment_status_and_partial_refund_fixtures_preserve_provider_truth(): void
    {
        foreach (['approved', 'pending', 'in_process', 'rejected', 'cancelled', 'refunded', 'charged_back', 'provider_future_state'] as $status) {
            $payload = [
                'id' => 'payment-'.$status,
                'collector_id' => 456,
                'external_reference' => 'external-'.$status,
                'status' => $status,
                'status_detail' => 'fixture-detail',
                'transaction_amount' => '100.00',
                'currency_id' => 'ARS',
                'transaction_amount_refunded' => $status === 'approved' ? '25.00' : null,
            ];

            $payment = (new MercadoPagoCheckoutProGateway(new RecordingTransport($payload), 'secret', 'configured-account'))
                ->fetchPayment('payment-'.$status);

            $this->assertSame($status, $payment->status);
            $this->assertSame('456', $payment->providerAccount);
            if ($status === 'approved') {
                $this->assertSame(2_500, $payment->settlement['refunded_minor']);
            }
            if ($status === 'charged_back') {
                $this->assertNull($payment->settlement['chargeback_minor']);
            }
        }
    }

    public function test_malformed_payment_money_is_rejected(): void
    {
        $gateway = new MercadoPagoCheckoutProGateway(new RecordingTransport([
            'id' => 'payment-malformed',
            'collector_id' => 456,
            'external_reference' => 'external-malformed',
            'status' => 'approved',
            'transaction_amount' => 'not-money',
            'currency_id' => 'ARS',
        ]), 'secret', 'configured-account');

        $this->expectException(\Throwable::class);
        $gateway->fetchPayment('payment-malformed');
    }

    public function test_webhook_signature_is_verified_before_json_is_exposed(): void
    {
        $secret = 'webhook-secret';
        $gateway = new MercadoPagoCheckoutProGateway(new RecordingTransport([]), $secret, 'seller-1');
        $timestamp = (string) (time() * 1000);
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

    public function test_webhook_rejects_missing_stale_and_malformed_inputs(): void
    {
        $gateway = new MercadoPagoCheckoutProGateway(new RecordingTransport([]), 'webhook-secret', 'seller-1');
        foreach ([
            new WebhookRequest('{}', [], ['data.id' => '123']),
            $this->signedWebhook('{}', (string) ((time() - 601) * 1000)),
            $this->signedWebhook('{malformed', (string) (time() * 1000)),
        ] as $request) {
            try {
                $gateway->verifyWebhook($request);
                $this->fail('Invalid webhook input must be rejected.');
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function signedWebhook(string $body, string $timestamp): WebhookRequest
    {
        $manifest = "id:123;request-id:req-test;ts:{$timestamp};";
        $signature = hash_hmac('sha256', $manifest, 'webhook-secret');

        return new WebhookRequest(
            $body,
            ['x-signature' => "ts={$timestamp},v1={$signature}", 'x-request-id' => 'req-test'],
            ['data.id' => '123'],
        );
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
            'collector_id' => 'seller-1',
            'external_reference' => 'external-1',
            'status' => 'approved',
            'transaction_amount' => 10000,
            'currency_id' => 'COP',
        ];
    }
}
