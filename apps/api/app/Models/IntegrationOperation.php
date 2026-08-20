<?php

namespace App\Models;

use App\Services\Integrations\SafeIntegrationError;
use LogicException;

class IntegrationOperation extends TenantModel
{
    protected function casts(): array
    {
        return ['safe_facts' => 'array', 'occurred_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (IntegrationOperation $operation): void {
            $operation->reason = SafeIntegrationError::from($operation->reason);
            $operation->safe_facts = SafeIntegrationError::value($operation->safe_facts);
        });
        static::updating(fn () => throw new LogicException('Integration operations are immutable.'));
        static::deleting(fn () => throw new LogicException('Integration operations are immutable.'));
    }
}
