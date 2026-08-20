<?php

namespace App\Services\Payments;

use App\Exceptions\CommercialWorkflowException as DomainException;
use App\Models\IntegrationConnection;

final class PaymentConnectionResolver
{
    public function forProperty(string $tenantId, string $propertyId): IntegrationConnection
    {
        $connection = IntegrationConnection::query()
            ->where('tenant_id', $tenantId)
            ->where('type', 'payment')
            ->where('provider', 'mercado_pago')
            ->where('product', 'checkout_pro')
            ->where('is_enabled', true)
            ->whereNull('revoked_at')
            ->whereNotNull('secret_reference')
            ->where(fn ($query) => $query->where('property_id', $propertyId)->orWhereNull('property_id'))
            ->whereHas('connectionCapabilities', fn ($query) => $query
                ->where('capability', 'payment.hosted_checkout')->where('direction', 'outbound')->where('state', 'enabled'))
            ->orderByRaw('CASE WHEN property_id = ? THEN 0 ELSE 1 END', [$propertyId])
            ->orderBy('external_account_id')->orderBy('id')->first();
        if ($connection === null) {
            throw new DomainException('No enabled Mercado Pago Checkout Pro connection is available for this property.');
        }

        return $connection;
    }

    public function assertAvailable(IntegrationConnection $connection, string $tenantId, ?string $propertyId): void
    {
        $capabilityEnabled = $connection->connectionCapabilities()->where('capability', 'payment.hosted_checkout')
            ->where('direction', 'outbound')->where('state', 'enabled')->exists();
        if ($connection->tenant_id !== $tenantId || $connection->type !== 'payment'
            || $connection->provider !== 'mercado_pago' || $connection->product !== 'checkout_pro'
            || $connection->external_account_id === '' || ! in_array($connection->environment, ['sandbox', 'production'], true)
            || ! $connection->is_enabled || $connection->revoked_at !== null || $connection->secret_reference === null
            || ($connection->property_id !== null && $connection->property_id !== $propertyId) || ! $capabilityEnabled) {
            throw new DomainException('The Mercado Pago connection is disabled, revoked, unsupported, or outside this property.');
        }
    }
}
