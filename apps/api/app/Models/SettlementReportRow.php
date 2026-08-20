<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SettlementReportRow extends TenantModel
{
    protected function casts(): array
    {
        return [
            'occurrence' => 'integer',
            'canonical_row' => 'array',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Settlement report rows are append-only.'));
        static::deleting(fn () => throw new LogicException('Settlement report rows cannot be deleted.'));
    }

    public function settlementReportImport(): BelongsTo
    {
        return $this->belongsTo(SettlementReportImport::class);
    }

    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function reportedAmount(): ?string
    {
        $canonical = $this->getAttribute('canonical_row');
        if (! is_array($canonical)) {
            return null;
        }
        $amount = $canonical['TRANSACTION_AMOUNT'] ?? $canonical['GROSS_AMOUNT'] ?? null;

        return is_string($amount) && $amount !== '' ? $amount : null;
    }
}
