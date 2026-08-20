<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $type
 * @property array<string, mixed>|null $configuration
 * @property string|null $secret_reference
 * @property string|null $payment_webhook_key
 */
class IntegrationConnection extends TenantModel
{
    public const TYPES = [
        'email' => 'Email',
        'calendar' => 'Calendar',
        'accounting' => 'Accounting',
        'payment' => 'Payment',
        'signature' => 'Signature',
        'webhook' => 'Webhook',
    ];

    protected function casts(): array
    {
        return [
            'configuration' => 'array',
            'last_synced_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (IntegrationConnection $connection): void {
            $connection->payment_webhook_key = $connection->type === 'payment'
                ? data_get($connection->configuration, 'webhook_key')
                : null;
        });
    }

    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }

    public function providerEvents(): HasMany
    {
        return $this->hasMany(ProviderEvent::class);
    }

    public function settlementEntries(): HasMany
    {
        return $this->hasMany(SettlementEntry::class);
    }
}
