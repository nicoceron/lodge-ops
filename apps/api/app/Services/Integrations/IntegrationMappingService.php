<?php

namespace App\Services\Integrations;

use App\Models\IntegrationConnection;
use App\Models\IntegrationMapping;
use App\Models\IntegrationReconciliation;
use App\Services\IntegrationConnectionService;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;

final class IntegrationMappingService
{
    /** @param array<string,mixed> $safeFacts */
    public function version(
        IntegrationConnection $connection,
        ?string $propertyId,
        string $capability,
        string $direction,
        string $localEntityType,
        string $localKey,
        string $externalEntityType,
        string $externalKey,
        int $transformVersion,
        array $safeFacts = [],
    ): IntegrationMapping {
        if (! in_array($capability, $connection->capabilities ?? [], true)
            || (IntegrationConnectionService::CAPABILITY_DIRECTIONS[$capability] ?? null) !== $direction) {
            throw new DomainException('The mapping capability or direction is not granted by this connection.');
        }
        if ($connection->property_id !== null && $propertyId !== $connection->property_id) {
            throw new DomainException('The mapping property must match its connection scope.');
        }
        if ($propertyId !== null && ! app(TenantContext::class)->canAccessProperty($propertyId)) {
            throw new DomainException('The mapping property is outside your workspace.');
        }
        $checksum = hash('sha256', json_encode($safeFacts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return DB::transaction(function () use ($connection, $propertyId, $capability, $direction, $localEntityType, $localKey, $externalEntityType, $externalKey, $transformVersion, $safeFacts, $checksum): IntegrationMapping {
            $previous = IntegrationMapping::query()->where('integration_connection_id', $connection->id)
                ->where('property_scope_key', $propertyId ?: '00000000-0000-0000-0000-000000000000')
                ->where('direction', $direction)->where('external_entity_type', $externalEntityType)
                ->where('external_key', $externalKey)->whereNull('valid_until')->lockForUpdate()->latest('valid_from')->first();
            if ($previous !== null && ($previous->local_entity_type !== $localEntityType || $previous->local_key !== $localKey)) {
                $previous->update(['conflict_state' => 'drift', 'valid_until' => now()]);
                IntegrationReconciliation::query()->create([
                    'integration_connection_id' => $connection->id,
                    'property_id' => $propertyId,
                    'kind' => 'mapping_drift',
                    'external_key' => $externalKey,
                    'local_key' => $previous->local_key,
                    'status' => 'open',
                    'reason_code' => 'external_identity_remapped',
                    'safe_facts' => ['previous_mapping_id' => $previous->id, 'candidate_local_key' => $localKey],
                ]);
            } elseif ($previous !== null) {
                $previous->update(['valid_until' => now()]);
            }

            return IntegrationMapping::query()->create([
                'integration_connection_id' => $connection->id,
                'property_id' => $propertyId,
                'capability' => $capability,
                'direction' => $direction,
                'local_entity_type' => $localEntityType,
                'local_key' => $localKey,
                'external_entity_type' => $externalEntityType,
                'external_key' => $externalKey,
                'transform_version' => $transformVersion,
                'conflict_state' => $previous?->conflict_state === 'drift' ? 'drift' : 'clear',
                'valid_from' => now(),
                'facts_checksum' => $checksum,
                'safe_facts' => $safeFacts,
            ]);
        }, 3);
    }
}
