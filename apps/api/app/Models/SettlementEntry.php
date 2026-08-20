<?php

namespace App\Models;

use App\Enums\SettlementReconciliationState;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $property_id
 * @property SettlementReconciliationState $reconciliation_state
 */
class SettlementEntry extends TenantModel
{
    protected function casts(): array
    {
        return [
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
            'reconciliation_state' => SettlementReconciliationState::class,
            'investigated_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(SettlementEntryRevision::class)->orderBy('revision');
    }

    public function varianceActions(): HasMany
    {
        return $this->hasMany(SettlementVarianceAction::class)->orderBy('acted_at');
    }

    public function integrationConnection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class);
    }
}
