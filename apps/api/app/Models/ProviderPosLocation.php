<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $property_id
 * @property string $integration_connection_id
 * @property string $external_pos_id
 * @property string $provider_account
 * @property string $environment
 * @property string $qr_mode
 * @property bool $is_enabled
 * @property string $health_state
 * @property string|null $replaced_by_id
 * @property CarbonImmutable|null $last_synced_at
 * @property CarbonImmutable|null $last_successful_order_at
 * @property CarbonImmutable|null $disabled_at
 */
class ProviderPosLocation extends TenantModel
{
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'last_synced_at' => 'immutable_datetime',
            'last_successful_order_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function integrationConnection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }

    public function replacement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }
}
