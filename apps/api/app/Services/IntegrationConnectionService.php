<?php

namespace App\Services;

use App\Models\IntegrationConnection;
use App\Models\IntegrationConnectionCapability;
use App\Models\IntegrationEvent;
use App\Models\IntegrationSyncRun;
use App\Models\IntegrationSyncRunItem;
use App\Services\Integrations\IntegrationConfigurationPolicy;
use App\Services\Integrations\IntegrationOperationRecorder;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;

class IntegrationConnectionService
{
    public function __construct(private readonly IntegrationConfigurationPolicy $configurationPolicy) {}

    public const CAPABILITY_DIRECTIONS = [
        'payment.hosted_checkout' => 'outbound',
        'payment.point_orders' => 'outbound',
        'payment.qr_orders' => 'outbound',
        'reservations.import' => 'inbound',
        'accounting.journal_export' => 'outbound',
        'webhook.inbound' => 'inbound',
        'webhook.outbound' => 'outbound',
    ];

    /** @param array<string, mixed> $configuration */
    public function configure(
        string $name,
        string $type,
        array $configuration,
        ?string $secretReference,
        ?string $propertyId = null,
        ?string $provider = null,
        ?string $product = null,
        ?string $externalAccountId = null,
        ?string $environment = null,
        array $capabilities = [],
        ?string $providerApplicationId = null,
    ): IntegrationConnection {
        if (! array_key_exists($type, IntegrationConnection::TYPES)) {
            throw new DomainException('Unsupported integration type.');
        }

        $this->assertSecretReference($secretReference);

        $membershipScope = app(TenantContext::class)->propertyScopeId();
        $propertyId ??= $membershipScope;
        if ($propertyId !== null && ! app(TenantContext::class)->canAccessProperty($propertyId)) {
            throw new DomainException('The integration property is outside your workspace.');
        }
        $provider ??= $type;
        $product ??= $provider === 'mercado_pago' ? 'checkout_pro' : $type;
        $externalAccountId ??= $name;
        $environment ??= 'sandbox';
        foreach ([$provider, $product, $externalAccountId, $environment] as $identity) {
            if (trim($identity) === '') {
                throw new DomainException('Provider, product, external account, and environment identity are required.');
            }
        }
        if ($provider === 'mercado_pago' && $product === 'orders' && trim((string) $providerApplicationId) === '') {
            throw new DomainException('Mercado Pago Orders requires its canonical provider application identity.');
        }
        $capabilities = array_values(array_unique(array_map('strval', $capabilities ?: ($type === 'payment' ? ['payment.hosted_checkout'] : []))));
        if (array_diff($capabilities, array_keys(self::CAPABILITY_DIRECTIONS)) !== []) {
            throw new DomainException('The connection contains an unsupported capability.');
        }
        $configuration = $this->configurationPolicy->validate($configuration, $type, $provider, $product, $capabilities);

        return DB::transaction(function () use ($name, $type, $configuration, $secretReference, $propertyId, $provider, $product, $externalAccountId, $environment, $capabilities, $providerApplicationId): IntegrationConnection {
            $connection = IntegrationConnection::query()->firstOrNew([
                'provider' => $provider,
                'product' => $product,
                'external_account_id' => $externalAccountId,
                'environment' => $environment,
                'property_scope_key' => $propertyId ?: '00000000-0000-0000-0000-000000000000',
            ]);
            $connection->fill([
                'property_id' => $propertyId,
                'name' => $name,
                'type' => $type,
                'provider_application_id' => $providerApplicationId,
                'status' => $secretReference === null ? 'disconnected' : 'configured',
                'secret_reference' => $secretReference,
                'configuration' => $configuration,
                'capabilities' => $capabilities,
                'configuration_version' => $connection->exists ? ((int) $connection->configuration_version + 1) : 1,
                'last_error' => null,
                'health_status' => 'untested',
            ]);
            $connection->save();
            foreach ($capabilities as $capability) {
                $direction = self::CAPABILITY_DIRECTIONS[$capability];
                IntegrationConnectionCapability::query()->updateOrCreate(
                    ['integration_connection_id' => $connection->id, 'capability' => $capability, 'direction' => $direction],
                    ['state' => 'disabled', 'configuration_version' => $connection->configuration_version],
                );
            }

            return $connection->fresh();
        }, 3);
    }

    public function enable(IntegrationConnection $connection, ?int $actorId, string $reason): IntegrationConnection
    {
        if ($connection->revoked_at !== null || $connection->secret_reference === null) {
            throw new DomainException('A revoked or unconfigured connection cannot be enabled.');
        }

        return DB::transaction(function () use ($connection, $actorId, $reason): IntegrationConnection {
            $connection->update(['is_enabled' => true, 'status' => 'connected']);
            $connection->connectionCapabilities()->update(['state' => 'enabled']);
            IntegrationOperationRecorder::record($connection, 'enabled', $actorId, $reason);

            return $connection->fresh();
        });
    }

    public function disable(IntegrationConnection $connection, ?int $actorId, string $reason): IntegrationConnection
    {
        return DB::transaction(function () use ($connection, $actorId, $reason): IntegrationConnection {
            $locked = IntegrationConnection::query()->lockForUpdate()->findOrFail($connection->id);
            $locked->update(['is_enabled' => false, 'status' => 'disabled']);
            $locked->connectionCapabilities()->update(['state' => 'disabled']);
            $runIds = IntegrationSyncRun::query()->where('integration_connection_id', $locked->id)
                ->whereIn('status', ['queued', 'running'])->lockForUpdate()->pluck('id');
            IntegrationSyncRun::query()->whereIn('id', $runIds)->update([
                'status' => 'blocked', 'last_error' => 'Connection disabled during run.',
                'claim_token' => null, 'lease_expires_at' => null,
            ]);
            IntegrationSyncRunItem::query()->whereIn('integration_sync_run_id', $runIds)
                ->whereIn('status', ['pending', 'retryable', 'processing'])->update([
                    'status' => 'blocked', 'last_error' => 'Connection disabled during run.',
                ]);
            IntegrationEvent::query()->where('integration_connection_id', $locked->id)
                ->whereIn('disposition', ['received', 'retryable', 'processing'])->update([
                    'disposition' => 'blocked', 'last_error' => 'Connection disabled before event processing.',
                ]);
            IntegrationOperationRecorder::record($locked, 'disabled', $actorId, $reason, ['blocked_run_count' => $runIds->count()]);

            return $locked->fresh();
        });
    }

    public function revoke(IntegrationConnection $connection, ?int $actorId, string $reason): IntegrationConnection
    {
        return DB::transaction(function () use ($connection, $actorId, $reason): IntegrationConnection {
            $locked = IntegrationConnection::query()->lockForUpdate()->findOrFail($connection->id);
            $locked->update(['is_enabled' => false, 'status' => 'revoked', 'revoked_at' => now(), 'secret_reference' => null]);
            $locked->connectionCapabilities()->update(['state' => 'revoked']);
            $runIds = IntegrationSyncRun::query()->where('integration_connection_id', $locked->id)
                ->whereIn('status', ['queued', 'running'])->lockForUpdate()->pluck('id');
            IntegrationSyncRun::query()->whereIn('id', $runIds)->update([
                'status' => 'blocked', 'last_error' => 'Connection revoked during run.',
                'claim_token' => null, 'lease_expires_at' => null,
            ]);
            IntegrationSyncRunItem::query()->whereIn('integration_sync_run_id', $runIds)
                ->whereIn('status', ['pending', 'retryable', 'processing'])->update([
                    'status' => 'blocked', 'last_error' => 'Connection revoked during run.',
                ]);
            IntegrationEvent::query()->where('integration_connection_id', $locked->id)
                ->whereIn('disposition', ['received', 'retryable', 'processing'])->update([
                    'disposition' => 'blocked', 'last_error' => 'Connection revoked before event processing.',
                ]);
            IntegrationOperationRecorder::record($locked, 'revoked', $actorId, $reason, ['blocked_run_count' => $runIds->count()]);

            return $locked->fresh();
        });
    }

    public function rotateSecretReference(IntegrationConnection $connection, string $reference, ?int $actorId, string $reason): IntegrationConnection
    {
        $this->assertSecretReference($reference);

        return DB::transaction(function () use ($connection, $reference, $actorId, $reason): IntegrationConnection {
            $connection->update(['secret_reference' => $reference, 'configuration_version' => $connection->configuration_version + 1, 'health_status' => 'untested']);
            IntegrationOperationRecorder::record($connection, 'secret_reference_rotated', $actorId, $reason, ['configuration_version' => $connection->configuration_version]);

            return $connection->fresh();
        });
    }

    private function assertSecretReference(?string $reference): void
    {
        if ($reference !== null && ! $this->configurationPolicy->isSecretReference($reference)) {
            throw new DomainException('Secret references must use an approved secret-manager URI.');
        }
    }
}
