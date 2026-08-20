<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $communication_id
 * @property string $status
 * @property string $idempotency_key
 * @property int $attempt
 * @property CarbonImmutable $attempted_at
 */
class DeliveryAttempt extends TenantModel
{
    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'attempted_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'reconcile_after' => 'immutable_datetime',
        ];
    }

    public function communication(): BelongsTo
    {
        return $this->belongsTo(Communication::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(CommunicationProviderConnection::class, 'communication_provider_connection_id');
    }
}
