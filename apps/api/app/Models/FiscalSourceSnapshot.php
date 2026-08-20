<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class FiscalSourceSnapshot extends TenantModel
{
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Fiscal source snapshots are immutable.'));
        static::deleting(fn () => throw new LogicException('Fiscal source snapshots are immutable.'));
    }

    protected function casts(): array
    {
        return [
            'reservation_revision' => 'integer', 'net_minor' => 'integer', 'tax_minor' => 'integer',
            'gross_minor' => 'integer', 'source_snapshot' => 'array', 'captured_at' => 'immutable_datetime',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
