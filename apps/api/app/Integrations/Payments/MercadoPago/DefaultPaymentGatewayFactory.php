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

        $transport = data_get($connection->configuration, 'transport') === 'deterministic_fixture'
            ? $this->deterministicTransport($connection)
            : new LaravelHttpMercadoPagoTransport($this->secrets->resolve($connection->secret_reference));

        return new MercadoPagoCheckoutProGateway(
            $transport,
            $this->secrets->resolve(data_get($connection->configuration, 'webhook_secret_reference')),
            (string) data_get($connection->configuration, 'provider_account'),
            (string) data_get($connection->configuration, 'environment', 'sandbox'),
            is_bool($configuredCheckoutUrlMode) ? $configuredCheckoutUrlMode : null,
        );
    }

    private function deterministicTransport(IntegrationConnection $connection): MercadoPagoTransport
    {
        $providerEnvironment = strtolower(trim((string) data_get($connection->configuration, 'environment')));
        if ($providerEnvironment !== 'sandbox' || ! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('The deterministic provider transport is restricted to explicit sandbox provider connections in local and test environments.');
        }

        $fixture = data_get($connection->configuration, 'fixture');
        if (! is_array($fixture)) {
            throw new RuntimeException('The deterministic provider fixture is missing.');
        }

        return new DeterministicMercadoPagoTransport($fixture);
    }
}
