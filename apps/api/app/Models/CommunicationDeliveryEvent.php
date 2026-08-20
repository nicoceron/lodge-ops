<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $property_id
 * @property string $processing_state
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $processed_at
 */
class CommunicationDeliveryEvent extends TenantModel
{
    protected static function booted(): void
    {
        static::updating(function (self $event): void {
            $processingFields = ['delivery_attempt_id', 'processing_state', 'processing_error', 'processed_at', 'updated_at'];
            if (array_diff(array_keys($event->getDirty()), $processingFields) !== []) {
                throw new \LogicException('Communication delivery event facts are immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Communication delivery events cannot be deleted.'));
    }

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'normalized_payload' => 'array',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(CommunicationProviderConnection::class, 'communication_provider_connection_id');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function deliveryAttempt(): BelongsTo
    {
        return $this->belongsTo(DeliveryAttempt::class);
    }
}
