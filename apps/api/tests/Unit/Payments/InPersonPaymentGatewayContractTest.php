<?php

namespace Tests\Unit\Payments;

use App\Data\Payments\PointOrderRequest;
use App\Data\Payments\ProviderOrderMutation;
use App\Data\Payments\ProviderOrderRefundRequest;
use App\Data\Payments\ProviderTerminal;
use App\Data\Payments\ProviderTerminalQuery;
use App\Data\Payments\QrOrderRequest;
use App\Data\Payments\WebhookRequest;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\FakeInPersonPaymentGateway;

class InPersonPaymentGatewayContractTest extends TestCase
{
    private const FIXTURE_PATH = __DIR__.'/../../Fixtures/MercadoPago/Orders';

    public function test_point_fixture_set_covers_every_documented_order_state(): void
    {
        $fixtures = $this->fixtures('point-*.json');
        $states = array_map(fn (array $fixture): string => $fixture['status'], $fixtures);

        $this->assertEqualsCanonicalizing(
            ['created', 'at_terminal', 'processed', 'failed', 'canceled', 'expired', 'action_required', 'refunded'],
            array_values(array_unique($states)),
        );

        $actionRequired = $this->fixture('point-action-required.json');
        $this->assertSame('action_required', $actionRequired['status_detail']);
        $this->assertSame('check_on_terminal', $actionRequired['transactions']['payments'][0]['status_detail']);

        $processed = $this->fixture('point-processed.json');
        $this->assertSame('processed', $processed['status_detail']);
        $this->assertSame('accredited', $processed['transactions']['payments'][0]['status_detail']);
    }

    public function test_qr_fixtures_cover_all_modes_without_inventing_a_declined_order_state(): void
    {
        $fixtures = $this->fixtures('qr-*.json');
        $modes = array_map(fn (array $fixture): string => $fixture['config']['qr']['mode'], $fixtures);
        $states = array_map(fn (array $fixture): string => $fixture['status'], $fixtures);

        $this->assertEqualsCanonicalizing(['static', 'dynamic', 'hybrid'], array_values(array_unique($modes)));
        $this->assertNotContains('failed', $states);
        $this->assertArrayNotHasKey('type_response', $this->fixture('qr-static-created.json'));
        $this->assertArrayHasKey('qr_data', $this->fixture('qr-dynamic-created.json')['type_response']);
        $this->assertArrayHasKey('qr_data', $this->fixture('qr-hybrid-created.json')['type_response']);

        $processed = $this->fixture('qr-processed.json');
        $this->assertSame('processed', $processed['status_detail']);
        $this->assertSame('accredited', $processed['transactions']['payments'][0]['status_detail']);
    }

    public function test_failure_fixtures_cover_required_http_and_transport_classes(): void
    {
        $fixtures = $this->fixture('orders-errors.json');
        $httpStatuses = array_values(array_unique(array_filter(array_column($fixtures, 'http_status'))));
        $transportScenarios = array_values(array_filter($fixtures, fn (array $fixture): bool => isset($fixture['transport_error'])));
        $rateLimit = array_values(array_filter($fixtures, fn (array $fixture): bool => ($fixture['http_status'] ?? null) === 429))[0];

        $this->assertEqualsCanonicalizing([400, 401, 403, 409, 425, 428, 429, 500], $httpStatuses);
        $this->assertSame('7', $rateLimit['headers']['Retry-After']);
        $this->assertCount(3, $transportScenarios);
        $this->assertStringContainsString('payments', file_get_contents(self::FIXTURE_PATH.'/malformed-response.txt'));
        $this->expectException(\JsonException::class);
        json_decode(file_get_contents(self::FIXTURE_PATH.'/malformed-response.txt'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_fake_gateway_preserves_channel_identity_and_operation_specific_idempotency(): void
    {
        $gateway = new FakeInPersonPaymentGateway;
        $gateway->terminals = [
            new ProviderTerminal('NEWLAND_N950__SBX0000001', 'PDV', 'store-1', 'pos-1', 'INN-TEST-POS-1'),
            new ProviderTerminal('NEWLAND_N950__OTHER', 'STANDALONE', 'store-2', 'pos-2', 'INN-TEST-POS-2'),
        ];

        $this->assertCount(1, $gateway->listTerminals(new ProviderTerminalQuery(storeId: 'store-1')));

        $pointRequest = new PointOrderRequest(
            externalReference: 'inn_point_1',
            idempotencyKey: 'create-point-1',
            requestChecksum: 'checksum-point-1',
            amountMinor: 2_400,
            currency: 'ARS',
            description: 'Reservation deposit',
            terminalId: 'NEWLAND_N950__SBX0000001',
        );
        $point = $gateway->createPointOrder($pointRequest);
        $replayedPoint = $gateway->createPointOrder(new PointOrderRequest(
            externalReference: 'inn_point_1',
            idempotencyKey: 'create-point-1',
            requestChecksum: 'caller-checksum-may-change',
            amountMinor: 2_400,
            currency: 'ARS',
            description: 'Reservation deposit',
            terminalId: 'NEWLAND_N950__SBX0000001',
        ));

        $this->assertSame($point, $replayedPoint);
        $this->assertCount(1, $gateway->pointCreates);
        $this->assertSame('point', $point->type);
        $this->assertSame('NEWLAND_N950__SBX0000001', $point->terminalId);
        $this->assertNull($point->externalPosId);

        $qr = $gateway->createQrOrder(new QrOrderRequest(
            externalReference: 'inn_qr_1',
            idempotencyKey: 'create-qr-1',
            requestChecksum: 'checksum-qr-1',
            amountMinor: 5_000,
            currency: 'ARS',
            description: 'Reservation balance',
            externalPosId: 'INN-TEST-POS-1',
            mode: 'dynamic',
        ));

        $this->assertSame('qr', $qr->type);
        $this->assertSame('INN-TEST-POS-1', $qr->externalPosId);
        $this->assertNotNull($qr->qrData);

        $canceled = $gateway->cancelOrder(new ProviderOrderMutation($point->id, 'create-point-1', 'checksum-cancel-1'));
        $this->assertSame('canceled', $canceled->status);
        $this->assertSame(
            $canceled,
            $gateway->cancelOrder(new ProviderOrderMutation($point->id, 'create-point-1', 'caller-checksum-may-change')),
        );
        $this->assertCount(1, $gateway->cancellations);

        $refund = $gateway->refundOrder(new ProviderOrderRefundRequest(
            providerOrderId: $qr->id,
            providerTransactionId: $qr->payments[0]->id,
            idempotencyKey: 'refund-qr-1',
            requestChecksum: 'checksum-refund-1',
            currency: 'ARS',
            amountMinor: 1_000,
        ));
        $this->assertSame('processed', $refund->status);
        $this->assertSame(1_000, $refund->amountMinor);

        $replayedRefund = $gateway->refundOrder(new ProviderOrderRefundRequest(
            providerOrderId: $qr->id,
            providerTransactionId: $qr->payments[0]->id,
            idempotencyKey: 'refund-qr-1',
            requestChecksum: 'caller-checksum-may-change',
            currency: 'ARS',
            amountMinor: 1_000,
        ));
        $this->assertSame($refund, $replayedRefund);
        $this->assertCount(1, $gateway->refunds);
    }

    #[DataProvider('changedPointOrderBodies')]
    public function test_fake_gateway_rejects_changed_actual_create_body_when_caller_checksum_is_constant(
        string $externalReference,
        int $amountMinor,
        string $currency,
    ): void {
        $gateway = new FakeInPersonPaymentGateway;
        $gateway->createPointOrder(new PointOrderRequest('inn_point_1', 'create-1', 'caller-constant', 2_400, 'ARS', 'Deposit', 'NEWLAND_N950__SBX0000001'));

        $this->expectException(LogicException::class);
        $gateway->createPointOrder(new PointOrderRequest($externalReference, 'create-1', 'caller-constant', $amountMinor, $currency, 'Deposit', 'NEWLAND_N950__SBX0000001'));
    }

    public function test_fake_gateway_rejects_changed_actual_refund_body_when_caller_checksum_is_constant(): void
    {
        $gateway = new FakeInPersonPaymentGateway;
        $order = $gateway->createQrOrder(new QrOrderRequest('inn_qr_1', 'create-qr-1', 'create-checksum', 5_000, 'ARS', 'Balance', 'INN-TEST-POS-1', 'dynamic'));
        $transactionId = $order->payments[0]->id;

        $gateway->refundOrder(new ProviderOrderRefundRequest($order->id, $transactionId, 'refund-1', 'caller-constant', 'ARS', 1_000));

        $this->expectException(LogicException::class);
        $gateway->refundOrder(new ProviderOrderRefundRequest($order->id, $transactionId, 'refund-1', 'caller-constant', 'ARS', 2_000));
    }

    public function test_order_webhook_fixture_uses_order_topic_and_resource_identity(): void
    {
        $gateway = new FakeInPersonPaymentGateway;
        $payload = file_get_contents(self::FIXTURE_PATH.'/order-webhook-processed.json');
        $event = $gateway->verifyWebhook(new WebhookRequest(
            rawBody: $payload,
            headers: ['x-request-id' => 'request-1'],
            query: ['type' => 'order', 'data.id' => 'ORD01TESTPOINTPROCESSED000001'],
        ));

        $this->assertSame('order', $event->topic);
        $this->assertSame('order', $event->type);
        $this->assertSame('order.processed', $event->action);
        $this->assertSame('ORD01TESTPOINTPROCESSED000001', $event->resourceId);
    }

    /** @return list<array<string, mixed>> */
    private function fixtures(string $pattern): array
    {
        $paths = glob(self::FIXTURE_PATH.'/'.$pattern);
        $this->assertNotFalse($paths);

        return array_map(fn (string $path): array => json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR), $paths);
    }

    /** @return array<string, mixed>|list<array<string, mixed>> */
    private function fixture(string $filename): array
    {
        return json_decode(file_get_contents(self::FIXTURE_PATH.'/'.$filename), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function changedPointOrderBodies(): iterable
    {
        yield 'external reference changes' => ['inn_point_2', 2_400, 'ARS'];
        yield 'amount changes' => ['inn_point_1', 2_500, 'ARS'];
        yield 'currency changes' => ['inn_point_1', 2_400, 'USD'];
    }
}
