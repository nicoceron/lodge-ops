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
        if ($connection->type !== 'payment' || $connection->provider !== 'mercado_pago' || $connection->product !== 'checkout_pro'
            || $connection->external_account_id === '' || ! in_array($connection->environment, ['sandbox', 'production'], true)
            || ! $connection->is_enabled || $connection->revoked_at !== null || $connection->secret_reference === null) {
            throw new RuntimeException('The selected payment connection is not supported.');
        }

        $configuredCheckoutUrlMode = data_get($connection->configuration, 'use_sandbox_checkout_url');

        $transport = data_get($connection->configuration, 'transport') === 'deterministic_fixture'
            ? $this->deterministicTransport($connection)
            : new LaravelHttpMercadoPagoTransport($this->secrets->resolve($connection->secret_reference));

        return new MercadoPagoCheckoutProGateway(
            $transport,
            $this->secrets->resolve(data_get($connection->configuration, 'webhook_secret_reference')),
            $connection->external_account_id,
            $connection->environment,
            is_bool($configuredCheckoutUrlMode) ? $configuredCheckoutUrlMode : null,
        );
    }

    private function deterministicTransport(IntegrationConnection $connection): MercadoPagoTransport
    {
        $providerEnvironment = strtolower(trim($connection->environment));
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
