<?php

namespace App\Services\Integrations;

use App\Contracts\Integrations\SecretReferenceResolver;
use App\Models\IntegrationConnection;
use App\Models\IntegrationEndpointKey;
use App\Models\IntegrationOperation;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class EndpointKeyService
{
    public function __construct(
        private readonly EndpointKeyRuntimeStore $runtime,
        private readonly SecretReferenceResolver $secrets,
    ) {}

    /** @return array{key:string,version:int,overlap_ends_at:?string} */
    public function rotate(IntegrationConnection $connection, int $overlapMinutes, ?int $actorId, string $reason, ?string $idempotencyKey = null): array
    {
        $raw = Str::random(64);
        $overlapMinutes = max(0, min(1440, $overlapMinutes));
        $idempotencyHash = $idempotencyKey === null ? null : hash('sha256', $idempotencyKey);
        $version = DB::transaction(function () use ($connection, $raw, $overlapMinutes, $actorId, $reason, $idempotencyHash): int {
            $locked = IntegrationConnection::query()->lockForUpdate()->findOrFail($connection->id);
            if ($idempotencyHash !== null && IntegrationOperation::query()->where('integration_connection_id', $locked->id)
                ->where('operation', 'endpoint_key_rotated')->where('idempotency_key_hash', $idempotencyHash)->exists()) {
                throw new DomainException('This endpoint rotation was already completed. Its raw key is not retained; use a new idempotency key to rotate again.');
            }
            $version = (int) $locked->webhook_key_version + 1;
            $overlapEnds = $overlapMinutes === 0 ? now() : now()->addMinutes($overlapMinutes);
            IntegrationEndpointKey::query()->where('integration_connection_id', $locked->id)->whereNull('revoked_at')->whereNull('expires_at')
                ->update(['expires_at' => $overlapEnds]);
            IntegrationEndpointKey::query()->create([
                'integration_connection_id' => $locked->id,
                'version' => $version,
                'key_hash' => hash('sha256', $raw),
                'valid_from' => now(),
            ]);
            $locked->update(['payment_webhook_key' => hash('sha256', $raw), 'webhook_key_version' => $version]);
            IntegrationOperationRecorder::record($locked, 'endpoint_key_rotated', $actorId, $reason, [
                'version' => $version, 'overlap_minutes' => $overlapMinutes,
            ], $idempotencyHash);

            return $version;
        }, 3);
        $this->runtime->put($connection, $raw);

        return ['key' => $raw, 'version' => $version, 'overlap_ends_at' => $overlapMinutes === 0 ? now()->toIso8601String() : now()->addMinutes($overlapMinutes)->toIso8601String()];
    }

    public function revokeAll(IntegrationConnection $connection, ?int $actorId, string $reason): void
    {
        DB::transaction(function () use ($connection, $actorId, $reason): void {
            IntegrationEndpointKey::query()->where('integration_connection_id', $connection->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $connection->update(['payment_webhook_key' => null]);
            IntegrationOperationRecorder::record($connection, 'endpoint_keys_revoked', $actorId, $reason);
        }, 3);
    }

    public function resolveConnection(string $rawKey): IntegrationConnection
    {
        $endpoint = IntegrationEndpointKey::withoutGlobalScopes()->where('key_hash', hash('sha256', $rawKey))
            ->whereNull('revoked_at')
            ->where('valid_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->firstOrFail();
        $connection = IntegrationConnection::withoutGlobalScopes()->findOrFail($endpoint->integration_connection_id);
        app(TenantContext::class)->set(Tenant::query()->findOrFail($connection->tenant_id));

        return $connection;
    }

    public function rawForOutbound(IntegrationConnection $connection): string
    {
        $issued = $this->runtime->issuedFor($connection);
        if ($issued !== null) {
            return $issued;
        }
        $reference = data_get($connection->configuration, 'webhook_endpoint_key_reference');
        if (is_string($reference) && $reference !== '') {
            $raw = $this->secrets->resolve($reference);
            if (hash('sha256', $raw) !== $connection->payment_webhook_key) {
                throw new RuntimeException('The referenced endpoint key does not match the active key version.');
            }

            return $raw;
        }

        throw new RuntimeException('Rotate the endpoint key and store the issued value in the configured secret manager before creating provider checkouts.');
    }
}
