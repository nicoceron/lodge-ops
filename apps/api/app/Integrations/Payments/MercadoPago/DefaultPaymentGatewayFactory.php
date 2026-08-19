<?php

namespace App\Integrations\Payments\MercadoPago;

use App\Contracts\Payments\PaymentGateway;
use App\Contracts\Payments\PaymentGatewayFactory;
use App\Models\IntegrationConnection;
use RuntimeException;

final class DefaultPaymentGatewayFactory implements PaymentGatewayFactory
{
    public function __construct(private readonly SecretReferenceResolver $secrets) {}

    public function for(IntegrationConnection $connection): PaymentGateway
    {
        if ($connection->type !== 'payment' || data_get($connection->configuration, 'provider') !== 'mercado_pago') {
            throw new RuntimeException('The selected payment connection is not supported.');
        }

        $configuredCheckoutUrlMode = data_get($connection->configuration, 'use_sandbox_checkout_url');

        return new MercadoPagoCheckoutProGateway(
            new LaravelHttpMercadoPagoTransport($this->secrets->resolve($connection->secret_reference)),
            $this->secrets->resolve(data_get($connection->configuration, 'webhook_secret_reference')),
            (string) data_get($connection->configuration, 'provider_account'),
            (string) data_get($connection->configuration, 'environment', 'sandbox'),
            is_bool($configuredCheckoutUrlMode) ? $configuredCheckoutUrlMode : null,
        );
    }
}
