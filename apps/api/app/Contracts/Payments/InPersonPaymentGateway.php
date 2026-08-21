<?php

namespace App\Contracts\Payments;

use App\Data\Payments\PointOrderRequest;
use App\Data\Payments\ProviderOrder;
use App\Data\Payments\ProviderOrderMutation;
use App\Data\Payments\ProviderOrderRefund;
use App\Data\Payments\ProviderOrderRefundRequest;
use App\Data\Payments\ProviderTerminal;
use App\Data\Payments\ProviderTerminalQuery;
use App\Data\Payments\QrOrderRequest;
use App\Data\Payments\VerifiedProviderEvent;
use App\Data\Payments\WebhookRequest;

interface InPersonPaymentGateway
{
    /** @return list<ProviderTerminal> */
    public function listTerminals(ProviderTerminalQuery $query): array;

    public function createPointOrder(PointOrderRequest $request): ProviderOrder;

    public function createQrOrder(QrOrderRequest $request): ProviderOrder;

    public function fetchOrder(string $providerOrderId): ProviderOrder;

    public function cancelOrder(ProviderOrderMutation $request): ProviderOrder;

    public function refundOrder(ProviderOrderRefundRequest $request): ProviderOrderRefund;

    public function verifyWebhook(WebhookRequest $request): VerifiedProviderEvent;
}
