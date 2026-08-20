<?php

namespace App\Models;

use App\Services\IntegrationConnectionService;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class IntegrationMapping extends TenantModel
{
    protected function casts(): array
    {
        return ['transform_version' => 'integer', 'safe_facts' => 'array', 'valid_from' => 'immutable_datetime', 'valid_until' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $mapping): void {
            $mapping->property_scope_key = $mapping->property_id ?: '00000000-0000-0000-0000-000000000000';
            $mapping->facts_checksum = hash('sha256', json_encode($mapping->safe_facts ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            if ($mapping->isDirty(['integration_connection_id', 'property_id', 'capability', 'direction'])) {
                $connection = IntegrationConnection::query()->findOrFail($mapping->integration_connection_id);
                if (! in_array($mapping->capability, $connection->capabilities ?? [], true)
                    || (IntegrationConnectionService::CAPABILITY_DIRECTIONS[$mapping->capability] ?? null) !== $mapping->direction
                    || ($connection->property_id !== null && $mapping->property_id !== $connection->property_id)) {
                    throw new DomainException('The mapping is outside the connection capability or property scope.');
                }
                $membershipScope = app(TenantContext::class)->propertyScopeId();
                if ($membershipScope !== null && $mapping->property_id !== $membershipScope) {
                    throw new DomainException('The mapping is outside your property scope.');
                }
            }
        });
        static::updating(function (self $mapping): void {
            if (array_diff(array_keys($mapping->getDirty()), ['conflict_state', 'valid_until', 'updated_at', 'property_scope_key']) !== []) {
                throw new LogicException('Integration mapping versions are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Integration mapping versions are immutable.'));
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }
}
