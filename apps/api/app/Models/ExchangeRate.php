<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $rate
 * @property CarbonImmutable $effective_at
 * @property string $source
 * @property string|null $property_id
 */
class ExchangeRate extends TenantModel
{
    protected function casts(): array
    {
        return [
            'rate' => 'decimal:10',
            'effective_at' => 'immutable_datetime',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Exchange-rate snapshots are immutable.'));
        static::deleting(fn () => throw new LogicException('Exchange-rate snapshots cannot be deleted.'));
    }
}
