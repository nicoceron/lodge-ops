<?php

namespace App\Services\Integrations;

use App\Models\IntegrationConnection;
use App\Models\IntegrationEndpointKey;
use App\Support\Tenancy\TenantContext;

/**
 * Request-process handoff for a raw endpoint key at issuance time only.
 * Persistent storage always receives SHA-256. Long-lived consumers must put
 * the raw key in their secret manager and configure webhook_endpoint_key_reference.
 */
final class EndpointKeyRuntimeStore
{
    /** @var array<int, string> */
    private array $pending = [];

    /** @var array<string, string> */
    private array $issued = [];

    public function remember(IntegrationConnection $connection, string $rawKey): void
    {
        $this->pending[spl_object_id($connection)] = $rawKey;
    }

    public function persistRemembered(IntegrationConnection $connection): void
    {
        $rawKey = $this->pending[spl_object_id($connection)] ?? null;
        unset($this->pending[spl_object_id($connection)]);
        if ($rawKey === null) {
            return;
        }
        $this->issued[$connection->id] = $rawKey;
        if (! app(TenantContext::class)->check()) {
            return;
        }
        IntegrationEndpointKey::query()->firstOrCreate(
            ['key_hash' => hash('sha256', $rawKey)],
            [
                'integration_connection_id' => $connection->id,
                'version' => max(1, (int) $connection->webhook_key_version),
                'valid_from' => now(),
            ],
        );
    }

    public function issuedFor(IntegrationConnection $connection): ?string
    {
        return $this->issued[$connection->id] ?? null;
    }

    public function put(IntegrationConnection $connection, string $rawKey): void
    {
        $this->issued[$connection->id] = $rawKey;
    }
}
