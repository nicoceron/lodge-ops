<?php

namespace App\Integrations\Payments\MercadoPago;

use App\Contracts\Payments\InPersonPaymentGateway;
use App\Data\Payments\ExactJsonDecimal;
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
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use JsonException;
use RuntimeException;

final class MercadoPagoOrdersGateway implements InPersonPaymentGateway
{
    public function __construct(
        private readonly MercadoPagoTransport $transport,
        private readonly string $webhookSecret,
        private readonly string $providerAccount,
        private readonly string $applicationId,
        private readonly string $environment,
    ) {}

    public function listTerminals(ProviderTerminalQuery $query): array
    {
        $payload = $this->transport->request('GET', '/terminals/v1/list', array_filter([
            'store_id' => $query->storeId,
            'pos_id' => $query->posId,
            'limit' => $query->limit,
            'offset' => $query->offset,
        ], fn (mixed $value): bool => $value !== null));
        $rows = data_get($payload, 'data.terminals', data_get($payload, 'terminals', data_get($payload, 'results', [])));
        if (! is_array($rows)) {
            throw new RuntimeException('Mercado Pago returned a malformed terminal list.');
        }

        return array_values(array_map(function (mixed $row): ProviderTerminal {
            if (! is_array($row) || ! is_string(data_get($row, 'id'))) {
                throw new RuntimeException('Mercado Pago returned a terminal without an identity.');
            }

            return new ProviderTerminal(
                data_get($row, 'id'),
                strtoupper((string) data_get($row, 'operating_mode', data_get($row, 'operatingMode', 'UNKNOWN'))),
                $this->nullableString(data_get($row, 'store_id')),
                $this->nullableString(data_get($row, 'pos_id')),
                $this->nullableString(data_get($row, 'external_pos_id')),
            );
        }, $rows));
    }

    public function createPointOrder(PointOrderRequest $request): ProviderOrder
    {
        return $this->normalizeOrder($this->transport->request('POST', '/v1/orders', [
            'type' => 'point',
            'total_amount' => $this->major($request->amountMinor),
            'description' => $request->description,
            'external_reference' => $request->externalReference,
            'expiration_time' => $request->expirationTime,
            'config' => ['point' => array_filter([
                'terminal_id' => $request->terminalId,
                'print_on_terminal' => $request->printOnTerminal,
                'ticket_number' => $request->ticketNumber,
            ], fn (mixed $value): bool => $value !== null)],
            'transactions' => ['payments' => [[
                'amount' => $this->major($request->amountMinor),
                ...($request->defaultPaymentMethodType === null ? [] : ['payment_method' => ['type' => $request->defaultPaymentMethodType]]),
            ]]],
        ], ['X-Idempotency-Key' => $request->idempotencyKey]));
    }

    public function createQrOrder(QrOrderRequest $request): ProviderOrder
    {
        if (! in_array($request->mode, ['static', 'dynamic', 'hybrid'], true)) {
            throw new RuntimeException('Unsupported Mercado Pago QR mode.');
        }

        return $this->normalizeOrder($this->transport->request('POST', '/v1/orders', [
            'type' => 'qr',
            'total_amount' => $this->major($request->amountMinor),
            'description' => $request->description,
            'external_reference' => $request->externalReference,
            'expiration_time' => $request->expirationTime,
            'config' => ['qr' => ['external_pos_id' => $request->externalPosId, 'mode' => $request->mode]],
            'transactions' => ['payments' => [[
                'amount' => $this->major($request->amountMinor),
            ]]],
        ], ['X-Idempotency-Key' => $request->idempotencyKey]));
    }

    public function fetchOrder(string $providerOrderId): ProviderOrder
    {
        return $this->normalizeOrder($this->transport->request('GET', '/v1/orders/'.rawurlencode($providerOrderId)));
    }

    public function cancelOrder(ProviderOrderMutation $request): ProviderOrder
    {
        return $this->normalizeOrder($this->transport->request(
            'POST',
            '/v1/orders/'.rawurlencode($request->providerOrderId).'/cancel',
            [],
            ['X-Idempotency-Key' => $request->idempotencyKey],
        ));
    }

    public function refundOrder(ProviderOrderRefundRequest $request): ProviderOrderRefund
    {
        $body = $request->amountMinor === null ? [] : ['transactions' => [[
            'id' => $request->providerTransactionId,
            'amount' => $this->major($request->amountMinor),
        ]]];
        $payload = $this->transport->request(
            'POST',
            '/v1/orders/'.rawurlencode($request->providerOrderId).'/refund',
            $body,
            ['X-Idempotency-Key' => $request->idempotencyKey],
        );
        $refunds = data_get($payload, 'transactions.refunds');
        $row = is_array($refunds) ? end($refunds) : false;
        if (! is_array($row) || ! is_string(data_get($row, 'id'))) {
            throw new RuntimeException('Mercado Pago returned a refund without an identity.');
        }

        return new ProviderOrderRefund(
            data_get($row, 'id'),
            (string) data_get($payload, 'id', $request->providerOrderId),
            (string) data_get($row, 'transaction_id', $request->providerTransactionId),
            (string) data_get($row, 'status', data_get($payload, 'status')),
            $this->minor(data_get($row, 'amount', $request->amountMinor === null ? 0 : $this->major($request->amountMinor)->value)),
            $request->currency,
            $this->nullableString(data_get($row, 'reference_id')),
            $this->nullableString(data_get($payload, 'status_detail')),
        );
    }

    public function verifyWebhook(WebhookRequest $request): VerifiedProviderEvent
    {
        $signature = $request->headers['x-signature'] ?? '';
        $requestId = $request->headers['x-request-id'] ?? '';
        preg_match('/(?:^|,)ts=([^,]+)/', $signature, $timestampMatch);
        preg_match('/(?:^|,)v1=([^,]+)/', $signature, $signatureMatch);
        $timestamp = $timestampMatch[1] ?? null;
        $provided = $signatureMatch[1] ?? null;
        $dataId = $request->query['data.id'] ?? $request->query['data_id'] ?? data_get($request->query, 'data.id');
        if (! ctype_digit((string) $timestamp) || ! is_string($provided) || $requestId === '' || ! is_string($dataId) || $dataId === '') {
            throw new RuntimeException('Invalid Mercado Pago Orders webhook signature headers.');
        }
        $timestampValue = (int) $timestamp;
        $timestampSeconds = strlen($timestamp) > 10 ? intdiv($timestampValue, 1000) : $timestampValue;
        if (abs(now()->timestamp - $timestampSeconds) > $request->toleranceSeconds) {
            throw new RuntimeException('Stale Mercado Pago Orders webhook signature.');
        }
        $manifest = 'id:'.strtolower($dataId).";request-id:{$requestId};ts:{$timestamp};";
        if (! hash_equals(hash_hmac('sha256', $manifest, $this->webhookSecret), strtolower($provided))) {
            throw new RuntimeException('Invalid Mercado Pago Orders webhook signature.');
        }
        try {
            $payload = json_decode($request->rawBody, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Malformed Mercado Pago Orders webhook JSON.', previous: $exception);
        }
        if (! is_array($payload) || data_get($payload, 'type') !== 'order'
            || (($request->query['type'] ?? 'order') !== 'order')) {
            throw new RuntimeException('Mercado Pago Orders webhook topic is not order.');
        }
        $bodyResourceId = data_get($payload, 'data.id');
        $bodyApplicationId = data_get($payload, 'application_id');
        $bodyProviderAccount = data_get($payload, 'user_id');
        $liveMode = data_get($payload, 'live_mode');
        if ((! is_string($bodyResourceId) && ! is_int($bodyResourceId)) || (string) $bodyResourceId !== $dataId
            || (! is_string($bodyApplicationId) && ! is_int($bodyApplicationId)) || (string) $bodyApplicationId !== $this->applicationId
            || (! is_string($bodyProviderAccount) && ! is_int($bodyProviderAccount)) || (string) $bodyProviderAccount !== $this->providerAccount
            || ! is_bool($liveMode) || ($liveMode ? 'production' : 'sandbox') !== $this->environment) {
            throw new RuntimeException('Mercado Pago Orders webhook resource/application/account/environment identity mismatch.');
        }

        return new VerifiedProviderEvent(
            deliveryId: $requestId,
            topic: 'order',
            type: 'order',
            action: (string) data_get($payload, 'action'),
            resourceId: $dataId,
            payload: $payload,
            providerCreatedAt: isset($payload['date_created']) ? CarbonImmutable::parse($payload['date_created']) : null,
            applicationId: (string) $bodyApplicationId,
            environment: $liveMode ? 'production' : 'sandbox',
            providerAccount: (string) $bodyProviderAccount,
        );
    }

    /** @param array<string, mixed> $payload */
    private function normalizeOrder(array $payload): ProviderOrder
    {
        $id = data_get($payload, 'id');
        $type = data_get($payload, 'type');
        $account = data_get($payload, 'user_id');
        $applicationId = data_get($payload, 'integration_data.application_id');
        $environment = $this->providerEnvironment($payload);
        $externalReference = data_get($payload, 'external_reference');
        $currency = data_get($payload, 'currency');
        if (! is_string($id) || ! in_array($type, ['point', 'qr'], true)
            || (! is_string($account) && ! is_int($account))
            || (! is_string($applicationId) && ! is_int($applicationId))
            || ! is_string($externalReference) || ! is_string($currency)) {
            throw new RuntimeException('Mercado Pago returned a malformed Orders resource identity.');
        }
        if ((string) $account !== $this->providerAccount || (string) $applicationId !== $this->applicationId
            || $environment !== $this->environment) {
            throw new RuntimeException('Mercado Pago returned an order owned by another application/account/environment.');
        }
        $payments = $this->transactions((array) data_get($payload, 'transactions.payments', []));
        if ($payments === []) {
            throw new RuntimeException('Mercado Pago returned an order without a payment transaction.');
        }
        $refunds = array_values(array_map(fn (array $row): ProviderOrderRefund => new ProviderOrderRefund(
            (string) data_get($row, 'id'),
            $id,
            (string) data_get($row, 'transaction_id'),
            (string) data_get($row, 'status'),
            $this->minor(data_get($row, 'amount')),
            $currency,
            $this->nullableString(data_get($row, 'reference_id')),
        ), array_filter((array) data_get($payload, 'transactions.refunds', []), 'is_array')));
        $amount = data_get($payload, 'total_amount', data_get($payload, 'total_paid_amount'));

        return new ProviderOrder(
            $id,
            $type,
            (string) $account,
            $externalReference,
            (string) data_get($payload, 'status'),
            $this->nullableString(data_get($payload, 'status_detail')),
            $amount === null ? $payments[0]->amountMinor : $this->minor($amount),
            $currency,
            $payments,
            $refunds,
            $this->nullableString(data_get($payload, 'config.point.terminal_id')),
            $this->nullableString(data_get($payload, 'config.qr.external_pos_id')),
            $this->nullableString(data_get($payload, 'config.qr.mode')),
            $this->nullableString(data_get($payload, 'type_response.qr_data')),
            data_get($payload, 'created_date', data_get($payload, 'date_created'))
                ? CarbonImmutable::parse(data_get($payload, 'created_date', data_get($payload, 'date_created'))) : null,
            data_get($payload, 'last_updated_date', data_get($payload, 'last_updated'))
                ? CarbonImmutable::parse(data_get($payload, 'last_updated_date', data_get($payload, 'last_updated'))) : null,
            (string) $applicationId,
            $environment,
        );
    }

    /** @param list<mixed> $rows @return list<ProviderOrderTransaction> */
    private function transactions(array $rows): array
    {
        $transactions = array_values(array_map(function (array $row): ProviderOrderTransaction {
            $id = data_get($row, 'id');
            if ((! is_string($id) && ! is_int($id)) || trim((string) $id) === '') {
                throw new RuntimeException('Mercado Pago returned a payment transaction without an identity.');
            }

            return new ProviderOrderTransaction(
                trim((string) $id),
                $this->minor(data_get($row, 'amount')),
                (string) data_get($row, 'status'),
                $this->nullableString(data_get($row, 'status_detail')),
                data_get($row, 'paid_amount') === null ? null : $this->minor(data_get($row, 'paid_amount')),
                data_get($row, 'refunded_amount') === null ? null : $this->minor(data_get($row, 'refunded_amount')),
                $this->nullableString(data_get($row, 'reference.id', data_get($row, 'reference_id'))),
                $this->nullableString(data_get($row, 'payment_method.type')),
                $this->nullableString(data_get($row, 'payment_method.id')),
                is_int(data_get($row, 'payment_method.installments')) ? data_get($row, 'payment_method.installments') : null,
            );
        }, array_filter($rows, 'is_array')));
        $ids = array_map(fn (ProviderOrderTransaction $transaction): string => $transaction->id, $transactions);
        if (count($ids) !== count(array_unique($ids))) {
            throw new RuntimeException('Mercado Pago returned duplicate payment transaction identities.');
        }
        usort($transactions, fn (ProviderOrderTransaction $left, ProviderOrderTransaction $right): int => strcmp($left->id, $right->id));

        return $transactions;
    }

    /** @param array<string, mixed> $payload */
    private function providerEnvironment(array $payload): string
    {
        $liveMode = data_get($payload, 'live_mode');
        if (is_bool($liveMode)) {
            return $liveMode ? 'production' : 'sandbox';
        }
        $environment = data_get($payload, 'environment');
        if (is_string($environment) && in_array(strtolower($environment), ['sandbox', 'production'], true)) {
            return strtolower($environment);
        }

        throw new RuntimeException('Mercado Pago returned an order without a known environment identity.');
    }

    private function major(int $minor): ExactJsonDecimal
    {
        return new ExactJsonDecimal(BigDecimal::of($minor)->dividedBy(100, 2)->__toString());
    }

    private function minor(mixed $major): int
    {
        return BigDecimal::of((string) ($major ?? 0))->multipliedBy(100)->toScale(0, RoundingMode::Unnecessary)->toInt();
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
