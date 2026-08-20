<?php

namespace App\Models;

use App\Services\Integrations\EndpointKeyRuntimeStore;
use App\Services\Integrations\SafeIntegrationError;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $type
 * @property string|null $property_id
 * @property string $provider
 * @property string $product
 * @property string $external_account_id
 * @property string $environment
 * @property array<string, mixed>|null $configuration
 * @property list<string>|null $capabilities
 * @property bool $is_enabled
 * @property int $configuration_version
 * @property int $webhook_key_version
 * @property int $circuit_failure_count
 * @property string|null $secret_reference
 * @property string|null $payment_webhook_key SHA-256 endpoint-key hash; never a raw key.
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $circuit_opened_at
 * @property CarbonImmutable|null $throttled_until
 * @property-read Property|null $property
 */
class IntegrationConnection extends TenantModel
{
    protected $hidden = ['secret_reference', 'payment_webhook_key', 'legacy_endpoint_key_ciphertext'];

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
            'capabilities' => 'array',
            'configuration_version' => 'integer',
            'webhook_key_version' => 'integer',
            'is_enabled' => 'boolean',
            'last_synced_at' => 'immutable_datetime',
            'last_success_at' => 'immutable_datetime',
            'last_error_at' => 'immutable_datetime',
            'last_event_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'circuit_opened_at' => 'immutable_datetime',
            'throttled_until' => 'immutable_datetime',
            'lag_seconds' => 'integer',
            'circuit_failure_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (IntegrationConnection $connection): void {
            if ($connection->last_error !== null) {
                $connection->last_error = SafeIntegrationError::from($connection->last_error);
            }
            $configuration = $connection->configuration ?? [];
            foreach ([
                'provider' => 'provider', 'product' => 'product', 'provider_account' => 'external_account_id',
                'external_account_id' => 'external_account_id', 'environment' => 'environment', 'property_id' => 'property_id',
            ] as $configurationKey => $column) {
                $legacy = data_get($configuration, $configurationKey);
                $canonical = $connection->getAttribute($column);
                if ($legacy !== null && $canonical !== null && (string) $legacy !== (string) $canonical) {
                    throw new DomainException('Integration configuration identity conflicts with its canonical columns.');
                }
                if ($canonical === null && $legacy !== null) {
                    $connection->setAttribute($column, $legacy);
                }
                data_forget($configuration, $configurationKey);
            }
            $connection->configuration = $configuration;
            $rawKey = data_get($configuration, 'webhook_key');
            if (is_string($rawKey) && $rawKey !== '') {
                app(EndpointKeyRuntimeStore::class)->remember($connection, $rawKey);
                $connection->payment_webhook_key = hash('sha256', $rawKey);
                data_forget($configuration, 'webhook_key');
                $connection->configuration = $configuration;
                $connection->webhook_key_version = max(1, (int) $connection->webhook_key_version);
            }
            $connection->property_scope_key = $connection->property_id ?: '00000000-0000-0000-0000-000000000000';
            $connection->provider ??= $connection->type;
            $connection->product ??= $connection->provider === 'mercado_pago' ? 'checkout_pro' : $connection->type;
            $connection->external_account_id ??= $connection->name;
            $connection->environment ??= 'sandbox';
        });

        static::saved(function (IntegrationConnection $connection): void {
            app(EndpointKeyRuntimeStore::class)->persistRemembered($connection);
        });
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function endpointKeys(): HasMany
    {
        return $this->hasMany(IntegrationEndpointKey::class);
    }

    public function connectionCapabilities(): HasMany
    {
        return $this->hasMany(IntegrationConnectionCapability::class);
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(IntegrationSyncRun::class);
    }

    public function integrationEvents(): HasMany
    {
        return $this->hasMany(IntegrationEvent::class);
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
