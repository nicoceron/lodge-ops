<?php

namespace App\Services\Payments;

use App\Contracts\Payments\InPersonPaymentGatewayFactory;
use App\Exceptions\CommercialWorkflowException;
use App\Models\PaymentAttempt;

final class ReconcileInPersonOrder
{
    public function __construct(
        private readonly InPersonPaymentGatewayFactory $gateways,
        private readonly ApplyMercadoPagoOrder $orders,
    ) {}

    public function handle(PaymentAttempt $attempt): PaymentAttempt
    {
        if ($attempt->provider_order_id === null) {
            throw new CommercialWorkflowException('The attempt has no provider order identity; its ambiguous create requires operator recovery before replacement.');
        }
        $remote = $this->gateways->for($attempt->integrationConnection)->fetchOrder($attempt->provider_order_id);

        return $this->orders->handle($attempt, $remote);
    }
}
