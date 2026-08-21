<?php

namespace App\Integrations\Payments\MercadoPago;

use App\Contracts\Payments\InPersonPaymentGateway;
use App\Contracts\Payments\InPersonPaymentGatewayFactory;
use App\Models\IntegrationConnection;
use RuntimeException;

final class DefaultInPersonPaymentGatewayFactory implements InPersonPaymentGatewayFactory
{
    public function __construct(private readonly SecretReferenceResolver $secrets) {}

    public function for(IntegrationConnection $connection): InPersonPaymentGateway
    {
        if ($connection->type !== 'payment' || $connection->provider !== 'mercado_pago'
            || ! in_array($connection->product, ['checkout_pro', 'orders'], true)
            || $connection->external_account_id === '' || ! in_array($connection->environment, ['sandbox', 'production'], true)
            || ! $connection->is_enabled || $connection->revoked_at !== null || $connection->secret_reference === null) {
            throw new RuntimeException('The selected Mercado Pago Orders connection is not supported.');
        }

        $transport = data_get($connection->configuration, 'transport') === 'deterministic_fixture'
            ? new DeterministicMercadoPagoTransport((array) data_get($connection->configuration, 'fixture', []), $connection->external_account_id)
            : new LaravelHttpMercadoPagoTransport($this->secrets->resolve($connection->secret_reference));

        return new MercadoPagoOrdersGateway(
            $transport,
            $this->secrets->resolve(data_get($connection->configuration, 'webhook_secret_reference')),
            $connection->external_account_id,
        );
    }
}
