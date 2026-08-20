<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string|null $property_id
 * @property string|null $guest_id
 * @property string|null $reservation_id
 * @property string $channel
 * @property string $direction
 * @property string $purpose
 * @property string $status
 * @property string|null $subject
 * @property string $body
 * @property string|null $content_checksum
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $failed_at
 * @property CarbonImmutable|null $status_occurred_at
 * @property CarbonImmutable|null $delivery_idempotency_started_at
 * @property CarbonImmutable|null $delivery_idempotency_expires_at
 * @property-read Guest|null $guest
 * @property-read Reservation|null $reservation
 */
class Communication extends TenantModel
{
    protected static function booted(): void
    {
        static::updating(function (self $communication): void {
            foreach (['delivery_idempotency_started_at', 'delivery_idempotency_expires_at'] as $attribute) {
                if ($communication->getOriginal($attribute) !== null && $communication->isDirty($attribute)) {
                    throw new \LogicException('A communication delivery idempotency window is immutable once anchored.');
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'sent_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'status_occurred_at' => 'immutable_datetime',
            'status_precedence' => 'integer',
            'delivery_idempotency_started_at' => 'immutable_datetime',
            'delivery_idempotency_expires_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function deliveryAttempts(): HasMany
    {
        return $this->hasMany(DeliveryAttempt::class);
    }
}
