<?php

namespace App\Services\Payments;

use App\Enums\PaymentChannel;
use App\Exceptions\CommercialWorkflowException as DomainException;
use App\Models\IntegrationConnection;

final class InPersonPaymentConnectionResolver
{
    public function forProperty(
        string $tenantId,
        string $propertyId,
        PaymentChannel $channel,
        string $currency,
        ?string $connectionId = null,
    ): IntegrationConnection {
        $capability = $channel === PaymentChannel::IntegratedTerminal ? 'payment.point_orders' : 'payment.qr_orders';
        $connection = IntegrationConnection::query()
            ->where('tenant_id', $tenantId)
            ->where('type', 'payment')
            ->where('provider', 'mercado_pago')
            ->whereIn('product', ['checkout_pro', 'orders'])
            ->where('is_enabled', true)
            ->whereNull('revoked_at')
            ->whereNotNull('secret_reference')
            ->where('configuration->charge_currency', strtoupper($currency))
            ->when($connectionId !== null, fn ($query) => $query->whereKey($connectionId))
            ->where(fn ($query) => $query->where('property_id', $propertyId)->orWhereNull('property_id'))
            ->whereHas('connectionCapabilities', fn ($query) => $query
                ->where('capability', $capability)->where('direction', 'outbound')->where('state', 'enabled'))
            ->orderByRaw('CASE WHEN property_id = ? THEN 0 ELSE 1 END', [$propertyId])
            ->orderBy('external_account_id')->orderBy('id')->first();
        if ($connection === null) {
            throw new DomainException('No enabled Mercado Pago Orders connection is available for this property and channel.');
        }

        return $connection;
    }
}
