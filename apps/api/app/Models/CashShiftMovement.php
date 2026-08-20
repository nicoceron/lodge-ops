<?php

namespace App\Models;

use App\Enums\CashMovementType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $property_id
 * @property string $cash_shift_id
 * @property string|null $payment_id
 * @property string|null $refund_change_id
 * @property string|null $reverses_movement_id
 * @property CashMovementType $type
 * @property int $amount_minor
 * @property string $currency
 * @property string|null $reason
 * @property CarbonImmutable $occurred_at
 */
class CashShiftMovement extends TenantModel
{
    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Cash shift movements are append-only.'));
        static::deleting(fn () => throw new \LogicException('Cash shift movements are append-only.'));
    }

    protected function casts(): array
    {
        return [
            'type' => CashMovementType::class,
            'amount_minor' => 'integer',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<CashShift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashShift::class, 'cash_shift_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function refundChange(): BelongsTo
    {
        return $this->belongsTo(ReservationChange::class, 'refund_change_id');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_movement_id');
    }

    /** @return HasOne<CashShiftMovement, $this> */
    public function correction(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_movement_id');
    }
}
