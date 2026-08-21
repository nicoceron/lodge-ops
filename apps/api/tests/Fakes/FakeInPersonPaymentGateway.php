<?php

namespace Tests\Fakes;

use App\Contracts\Payments\InPersonPaymentGateway;
use App\Contracts\Payments\InPersonPaymentGatewayFactory;
use App\Data\Payments\PointOrderRequest;
use App\Data\Payments\ProviderOrder;
use App\Data\Payments\ProviderOrderMutation;
use App\Data\Payments\ProviderOrderRefund;
use App\Data\Payments\ProviderOrderRefundRequest;
use App\Data\Payments\ProviderOrderTransaction;
use App\Data\Payments\ProviderTerminal;
use App\Data\Payments\ProviderTerminalQuery;
use App\Data\Payments\QrOrderRequest;
use App\Data\Payments\VerifiedProviderEvent;
use App\Data\Payments\WebhookRequest;
use App\Models\IntegrationConnection;

final class FakeInPersonPaymentGateway implements InPersonPaymentGateway, InPersonPaymentGatewayFactory
{
    /** @var list<ProviderTerminal> */
    public array $terminals = [];

    /** @var array<string, ProviderOrder> */
    public array $orders = [];

    /** @var list<PointOrderRequest> */
    public array $pointCreates = [];

    /** @var list<QrOrderRequest> */
    public array $qrCreates = [];

    /** @var list<ProviderOrderMutation> */
    public array $cancellations = [];

    /** @var list<ProviderOrderRefundRequest> */
    public array $refunds = [];

    public bool $cancelThrowsAfterRemoteSuccess = false;

    public string $refundStatus = 'processed';

    /** @var array<string, string> */
    private array $operationChecksums = [];

    /** @var array<string, ProviderOrder> */
    private array $operationOrders = [];

    /** @var array<string, ProviderOrderRefund> */
    private array $operationRefunds = [];

    public function for(IntegrationConnection $connection): InPersonPaymentGateway
    {
        return $this;
    }

    public function listTerminals(ProviderTerminalQuery $query): array
    {
        return array_values(array_filter(
            $this->terminals,
            fn (ProviderTerminal $terminal): bool => ($query->storeId === null || $terminal->storeId === $query->storeId)
                && ($query->posId === null || $terminal->posId === $query->posId),
        ));
    }

    public function createPointOrder(PointOrderRequest $request): ProviderOrder
    {
        $operationKey = $this->rememberOperation('create', $request->idempotencyKey, [
            'type' => 'point',
            'external_reference' => $request->externalReference,
            'amount_minor' => $request->amountMinor,
            'currency' => $request->currency,
            'description' => $request->description,
            'terminal_id' => $request->terminalId,
            'expiration_time' => $request->expirationTime,
            'print_on_terminal' => $request->printOnTerminal,
            'ticket_number' => $request->ticketNumber,
            'default_payment_method_type' => $request->defaultPaymentMethodType,
        ]);

        if (isset($this->operationOrders[$operationKey])) {
            return $this->operationOrders[$operationKey];
        }

        $this->pointCreates[] = $request;

        $order = $this->orders[$request->externalReference] ??= $this->makeOrder(
            type: 'point',
            externalReference: $request->externalReference,
            amountMinor: $request->amountMinor,
            currency: $request->currency,
            terminalId: $request->terminalId,
        );

        return $this->operationOrders[$operationKey] = $order;
    }

    public function createQrOrder(QrOrderRequest $request): ProviderOrder
    {
        $operationKey = $this->rememberOperation('create', $request->idempotencyKey, [
            'type' => 'qr',
            'external_reference' => $request->externalReference,
            'amount_minor' => $request->amountMinor,
            'currency' => $request->currency,
            'description' => $request->description,
            'external_pos_id' => $request->externalPosId,
            'mode' => $request->mode,
            'expiration_time' => $request->expirationTime,
        ]);

        if (isset($this->operationOrders[$operationKey])) {
            return $this->operationOrders[$operationKey];
        }

        $this->qrCreates[] = $request;

        $order = $this->orders[$request->externalReference] ??= $this->makeOrder(
            type: 'qr',
            externalReference: $request->externalReference,
            amountMinor: $request->amountMinor,
            currency: $request->currency,
            externalPosId: $request->externalPosId,
            qrMode: $request->mode,
            qrData: in_array($request->mode, ['dynamic', 'hybrid'], true) ? '000201010212FAKE-INN-QR' : null,
        );

        return $this->operationOrders[$operationKey] = $order;
    }

    public function fetchOrder(string $providerOrderId): ProviderOrder
    {
        foreach ($this->orders as $order) {
            if ($order->id === $providerOrderId) {
                return $order;
            }
        }

        throw new \RuntimeException('Fake provider order not found.');
    }

    public function cancelOrder(ProviderOrderMutation $request): ProviderOrder
    {
        $operationKey = $this->rememberOperation('cancel', $request->idempotencyKey, [
            'provider_order_id' => $request->providerOrderId,
        ]);

        if (isset($this->operationOrders[$operationKey])) {
            return $this->operationOrders[$operationKey];
        }

        $this->cancellations[] = $request;
        $order = $this->fetchOrder($request->providerOrderId);

        $canceled = new ProviderOrder(
            id: $order->id,
            type: $order->type,
            providerAccount: $order->providerAccount,
            externalReference: $order->externalReference,
            status: 'canceled',
            statusDetail: 'canceled',
            amountMinor: $order->amountMinor,
            currency: $order->currency,
            payments: $order->payments,
            refunds: $order->refunds,
            terminalId: $order->terminalId,
            externalPosId: $order->externalPosId,
            qrMode: $order->qrMode,
        );

        $this->orders[$order->externalReference] = $canceled;

        if ($this->cancelThrowsAfterRemoteSuccess) {
            throw new \RuntimeException('Synthetic timeout after remote cancel success.');
        }

        return $this->operationOrders[$operationKey] = $canceled;
    }

    public function refundOrder(ProviderOrderRefundRequest $request): ProviderOrderRefund
    {
        $operationKey = $this->rememberOperation('refund', $request->idempotencyKey, [
            'provider_order_id' => $request->providerOrderId,
            'provider_transaction_id' => $request->providerTransactionId,
            'amount_minor' => $request->amountMinor,
            'currency' => $request->currency,
        ]);

        if (isset($this->operationRefunds[$operationKey])) {
            return $this->operationRefunds[$operationKey];
        }

        $this->refunds[] = $request;
        $order = $this->fetchOrder($request->providerOrderId);

        return $this->operationRefunds[$operationKey] = new ProviderOrderRefund(
            id: 'REF'.strtoupper(substr(hash('sha256', $request->idempotencyKey), 0, 26)),
            providerOrderId: $order->id,
            providerTransactionId: $request->providerTransactionId,
            status: $this->refundStatus,
            amountMinor: $request->amountMinor ?? $order->amountMinor,
            currency: $request->currency,
            referenceId: 'fake-refund-reference',
        );
    }

    public function verifyWebhook(WebhookRequest $request): VerifiedProviderEvent
    {
        $payload = json_decode($request->rawBody, true, flags: JSON_THROW_ON_ERROR);
        $resourceId = (string) ($request->query['data.id'] ?? data_get($payload, 'data.id'));

        return new VerifiedProviderEvent(
            deliveryId: $request->headers['x-request-id'],
            topic: (string) ($request->query['type'] ?? data_get($payload, 'type')),
            type: (string) data_get($payload, 'type'),
            action: (string) data_get($payload, 'action'),
            resourceId: $resourceId,
            payload: $payload,
        );
    }

    /** @param array<string, mixed> $body */
    private function rememberOperation(string $operation, string $idempotencyKey, array $body): string
    {
        $operationKey = $operation.':'.$idempotencyKey;
        $checksum = hash('sha256', json_encode($this->canonicalize($body), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        if (isset($this->operationChecksums[$operationKey]) && $this->operationChecksums[$operationKey] !== $checksum) {
            throw new \LogicException('Idempotency key was reused with a different canonical request body.');
        }

        $this->operationChecksums[$operationKey] = $checksum;

        return $operationKey;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }

    private function makeOrder(
        string $type,
        string $externalReference,
        int $amountMinor,
        string $currency,
        ?string $terminalId = null,
        ?string $externalPosId = null,
        ?string $qrMode = null,
        ?string $qrData = null,
    ): ProviderOrder {
        $suffix = strtoupper(substr(hash('sha256', $type.':'.$externalReference), 0, 26));
        $orderId = 'ORD'.$suffix;

        return new ProviderOrder(
            id: $orderId,
            type: $type,
            providerAccount: 'TEST-SELLER-ID',
            externalReference: $externalReference,
            status: 'created',
            statusDetail: 'created',
            amountMinor: $amountMinor,
            currency: $currency,
            payments: [new ProviderOrderTransaction('PAY'.$suffix, $amountMinor, 'created', 'created')],
            terminalId: $terminalId,
            externalPosId: $externalPosId,
            qrMode: $qrMode,
            qrData: $qrData,
        );
    }
}
