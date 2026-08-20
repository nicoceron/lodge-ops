<?php

namespace App\Models;

use App\Enums\CashShiftState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $property_id
 * @property int $cashier_id
 * @property string $currency
 * @property CashShiftState $state
 * @property int $opening_float_minor
 * @property int|null $expected_cash_minor
 * @property int|null $counted_cash_minor
 * @property int|null $variance_minor
 * @property CarbonImmutable $business_date
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $approved_at
 * @property-read Property $property
 * @property-read User|null $cashier
 * @property-read Collection<int, CashShiftMovement> $movements
 */
class CashShift extends TenantModel
{
    protected function casts(): array
    {
        return [
            'state' => CashShiftState::class,
            'opening_float_minor' => 'integer',
            'expected_cash_minor' => 'integer',
            'counted_cash_minor' => 'integer',
            'variance_minor' => 'integer',
            'business_date' => 'immutable_date',
            'opened_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<User, $this> */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    /** @return HasMany<CashShiftMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(CashShiftMovement::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function currentExpectedMinor(): int
    {
        return (int) $this->movements()->sum('amount_minor');
    }
}
