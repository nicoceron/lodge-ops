<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SettlementEntryRevision extends TenantModel
{
    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'gross_minor' => 'integer',
            'fee_minor' => 'integer',
            'tax_minor' => 'integer',
            'withholding_minor' => 'integer',
            'financing_minor' => 'integer',
            'refunded_minor' => 'integer',
            'chargeback_minor' => 'integer',
            'net_minor' => 'integer',
            'settlement_date' => 'immutable_date',
            'payout_date' => 'immutable_date',
            'recorded_at' => 'immutable_datetime',
            'provider_facts' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Settlement revisions are append-only.'));
        static::deleting(fn () => throw new LogicException('Settlement revisions cannot be deleted.'));
    }

    public function settlementEntry(): BelongsTo
    {
        return $this->belongsTo(SettlementEntry::class);
    }
}
