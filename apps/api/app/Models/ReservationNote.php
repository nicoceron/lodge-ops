<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $reservation_id
 * @property string $kind
 * @property string $body
 * @property CarbonImmutable $occurred_at
 * @property int $created_by
 * @property-read User|null $creator
 */
class ReservationNote extends TenantModel
{
    public const KINDS = [
        'internal' => 'Internal',
        'guest_request' => 'Guest request',
        'operations' => 'Operations',
        'finance' => 'Finance',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (ReservationNote $note): void {
            $note->occurred_at ??= now();
        });
        static::updating(fn () => throw new LogicException('Reservation notes are append-only.'));
        static::deleting(fn () => throw new LogicException('Reservation notes are append-only.'));
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
