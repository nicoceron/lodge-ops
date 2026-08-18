<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property string $id
 * @property string $reservation_id
 * @property string|null $parent_change_id
 * @property int|null $actor_id
 * @property string $type
 * @property string $status
 * @property string|null $currency
 * @property int|null $amount_minor
 * @property string|null $reference
 * @property array<string, mixed>|null $before_snapshot
 * @property array<string, mixed>|null $after_snapshot
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable $occurred_at
 * @property-read Reservation $reservation
 * @property-read ReservationChange|null $parent
 * @property-read Collection<int, ReservationChange> $events
 * @property-read User|null $actor
 */
class ReservationChange extends TenantModel
{
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Reservation changes are append-only and cannot be edited.'));
        static::deleting(fn () => throw new LogicException('Reservation changes are append-only and cannot be deleted.'));
    }

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'before_snapshot' => 'array',
            'after_snapshot' => 'array',
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** @return BelongsTo<ReservationChange, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_change_id');
    }

    /** @return HasMany<ReservationChange, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(self::class, 'parent_change_id')->orderBy('occurred_at');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
