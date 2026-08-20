<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class OperationalTaskEvent extends TenantModel
{
    protected function casts(): array
    {
        return ['snapshot' => 'array', 'occurred_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Operational task events are append-only.'));
        static::deleting(fn () => throw new LogicException('Operational task events are append-only.'));
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(OperationalTask::class, 'operational_task_id');
    }
}
