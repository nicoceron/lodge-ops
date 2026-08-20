<?php

namespace App\Models;

use App\Enums\ProviderEventState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $integration_connection_id
 * @property string $provider
 * @property string $environment
 * @property string $provider_account
 * @property string|null $delivery_id
 * @property string|null $topic
 * @property string|null $event_type
 * @property string|null $action
 * @property string|null $resource_id
 * @property bool $signature_valid
 * @property ProviderEventState $processing_state
 * @property int $attempt_count
 * @property string|null $last_error
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $processed_at
 * @property-read IntegrationConnection $integrationConnection
 */
class ProviderEvent extends TenantModel
{
    protected $hidden = ['private_payload'];

    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'received_at' => 'immutable_datetime',
            'provider_created_at' => 'immutable_datetime',
            'processing_state' => ProviderEventState::class,
            'private_payload' => 'encrypted:array',
            'sanitized_headers' => 'array',
            'attempt_count' => 'integer',
            'processed_at' => 'immutable_datetime',
        ];
    }

    public function integrationConnection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class);
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_id');
    }
}
