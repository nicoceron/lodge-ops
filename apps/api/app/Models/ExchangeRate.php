<?php

namespace App\Models;

use LogicException;

class ExchangeRate extends TenantModel
{
    protected function casts(): array
    {
        return ['rate' => 'decimal:10', 'effective_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Exchange-rate snapshots are immutable.'));
        static::deleting(fn () => throw new LogicException('Exchange-rate snapshots cannot be deleted.'));
    }
}
