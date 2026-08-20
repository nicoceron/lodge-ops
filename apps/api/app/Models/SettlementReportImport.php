<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class SettlementReportImport extends TenantModel
{
    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'report_metadata' => 'array',
            'is_fixture' => 'boolean',
            'row_count' => 'integer',
            'imported_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Settlement report imports are append-only.'));
        static::deleting(fn () => throw new LogicException('Settlement report imports cannot be deleted.'));
    }

    public function integrationConnection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(SettlementReportRow::class);
    }
}
