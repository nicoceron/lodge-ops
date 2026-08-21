<?php

namespace Tests\Unit\Payments;

use App\Data\Payments\PointOrderRequest;
use App\Data\Payments\ProviderOrderMutation;
use App\Data\Payments\ProviderOrderRefundRequest;
use App\Data\Payments\QrOrderRequest;
use App\Data\Payments\WebhookRequest;
use App\Integrations\Payments\MercadoPago\MercadoPagoOrdersGateway;
use App\Integrations\Payments\MercadoPago\MercadoPagoTransport;
use PHPUnit\Framework\TestCase;

class MercadoPagoOrdersGatewayTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../Fixtures/MercadoPago/Orders';

    public function test_point_and_qr_create_use_orders_endpoint_numeric_money_and_operation_idempotency(): void
    {
        $transport = new OrdersRecordingTransport([$this->fixture('point-created.json'), $this->fixture('qr-dynamic-created.json')]);
        $gateway = new MercadoPagoOrdersGateway($transport, 'secret', 'TEST-SELLER-ID');

        $point = $gateway->createPointOrder(new PointOrderRequest('inn_point_created', 'point-key', 'point-checksum', 2_400, 'ARS', 'Inn deposit', 'NEWLAND_N950__SBX0000001'));
        $this->assertSame('/v1/orders', $transport->calls[0]['path']);
        $this->assertSame('point-key', $transport->calls[0]['headers']['X-Idempotency-Key']);
        $this->assertSame('24.00', (string) data_get($transport->calls[0], 'payload.total_amount'));
        $this->assertSame('Inn deposit', data_get($transport->calls[0], 'payload.description'));
        $this->assertSame('24.00', (string) data_get($transport->calls[0], 'payload.transactions.payments.0.amount'));
        $this->assertSame('point', $point->type);

        $qr = $gateway->createQrOrder(new QrOrderRequest('inn_qr_dynamic', 'qr-key', 'qr-checksum', 5_000, 'ARS', 'Inn balance', 'INN-TEST-POS-1', 'dynamic'));
        $this->assertSame('qr-key', $transport->calls[1]['headers']['X-Idempotency-Key']);
        $this->assertSame('50.00', (string) data_get($transport->calls[1], 'payload.total_amount'));
        $this->assertSame('Inn balance', data_get($transport->calls[1], 'payload.description'));
        $this->assertSame('dynamic', data_get($transport->calls[1], 'payload.config.qr.mode'));
        $this->assertSame('000201010212FAKE-INN-DYNAMIC-QR', $qr->qrData);
    }

    public function test_orders_cancel_and_refund_never_use_payments_refund_endpoint(): void
    {
        $transport = new OrdersRecordingTransport([$this->fixture('point-canceled.json'), $this->fixture('qr-refunded.json')]);
        $gateway = new MercadoPagoOrdersGateway($transport, 'secret', 'TEST-SELLER-ID');
        $gateway->cancelOrder(new ProviderOrderMutation('ORD01TESTPOINTCANCELED0000001', 'cancel-key', 'cancel-checksum'));
        $refund = $gateway->refundOrder(new ProviderOrderRefundRequest(
            'ORD01TESTQRREFUNDED000000001',
            'PAY01TESTQRREFUNDED000000001',
            'refund-key',
            'refund-checksum',
            'ARS',
            1_000,
        ));

        $this->assertSame('/v1/orders/ORD01TESTPOINTCANCELED0000001/cancel', $transport->calls[0]['path']);
        $this->assertSame('/v1/orders/ORD01TESTQRREFUNDED000000001/refund', $transport->calls[1]['path']);
        $this->assertStringNotContainsString('/v1/payments/', implode('|', array_column($transport->calls, 'path')));
        $this->assertSame(5_000, $refund->amountMinor);
    }

    public function test_order_signature_requires_singular_order_topic_and_lowercased_data_id_manifest(): void
    {
        $secret = 'orders-webhook-secret';
        $timestamp = (string) (time() * 1000);
        $id = 'ORD01JQ4S4KY8HWQ6NA5PXB65B3D3';
        $manifest = 'id:'.strtolower($id).";request-id:req-order-1;ts:{$timestamp};";
        $signature = hash_hmac('sha256', $manifest, $secret);
        $gateway = new MercadoPagoOrdersGateway(new OrdersRecordingTransport([]), $secret, 'TEST-SELLER-ID');
        $event = $gateway->verifyWebhook(new WebhookRequest(
            json_encode(['type' => 'order', 'action' => 'order.processed', 'data' => ['id' => $id]], JSON_THROW_ON_ERROR),
            ['x-signature' => "ts={$timestamp},v1={$signature}", 'x-request-id' => 'req-order-1'],
            ['type' => 'order', 'data.id' => $id],
        ));
        $this->assertSame('order', $event->topic);
        $this->assertSame($id, $event->resourceId);

        $this->expectException(\RuntimeException::class);
        $gateway->verifyWebhook(new WebhookRequest(
            '{"type":"payment"}',
            ['x-signature' => "ts={$timestamp},v1={$signature}", 'x-request-id' => 'req-order-1'],
            ['type' => 'payment', 'data.id' => $id],
        ));
    }

    public function test_order_signature_rejects_missing_invalid_and_stale_envelopes(): void
    {
        $secret = 'orders-webhook-secret';
        $id = 'ORD01JQ4S4KY8HWQ6NA5PXB65B3D3';
        $body = json_encode(['type' => 'order', 'action' => 'order.processed', 'data' => ['id' => $id]], JSON_THROW_ON_ERROR);
        $gateway = new MercadoPagoOrdersGateway(new OrdersRecordingTransport([]), $secret, 'TEST-SELLER-ID');
        $now = (string) (time() * 1000);
        $stale = (string) ((time() - 1_000) * 1000);
        $cases = [
            new WebhookRequest($body, ['x-request-id' => 'req-missing'], ['type' => 'order', 'data.id' => $id]),
            new WebhookRequest($body, ['x-request-id' => 'req-invalid', 'x-signature' => "ts={$now},v1=deadbeef"], ['type' => 'order', 'data.id' => $id]),
            new WebhookRequest($body, ['x-request-id' => 'req-stale', 'x-signature' => "ts={$stale},v1=deadbeef"], ['type' => 'order', 'data.id' => $id]),
        ];

        foreach ($cases as $request) {
            try {
                $gateway->verifyWebhook($request);
                $this->fail('An unauthenticated or stale Orders event was accepted.');
            } catch (\RuntimeException $exception) {
                $this->assertMatchesRegularExpression('/signature/i', $exception->getMessage());
            }
        }
    }

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(self::FIXTURES.'/'.$name), true, flags: JSON_THROW_ON_ERROR);
    }
}

final class OrdersRecordingTransport implements MercadoPagoTransport
{
    /** @var list<array{method:string,path:string,payload:array,headers:array}> */
    public array $calls = [];

    /** @param list<array<string, mixed>> $responses */
    public function __construct(private array $responses) {}

    public function request(string $method, string $path, array $payload = [], array $headers = []): array
    {
        $this->calls[] = compact('method', 'path', 'payload', 'headers');

        return array_shift($this->responses) ?? throw new \RuntimeException('No transport fixture queued.');
    }
}
