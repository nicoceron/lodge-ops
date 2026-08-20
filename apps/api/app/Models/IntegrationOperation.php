<?php

namespace App\Models;

use LogicException;

class IntegrationOperation extends TenantModel
{
    protected function casts(): array
    {
        return ['safe_facts' => 'array', 'occurred_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Integration operations are immutable.'));
        static::deleting(fn () => throw new LogicException('Integration operations are immutable.'));
    }
}
