<?php

namespace App\Models;

use App\Enums\SettlementReconciliationState;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettlementEntry extends TenantModel
{
    protected function casts(): array
    {
        return [
            'gross_minor' => 'integer',
            'fee_minor' => 'integer',
            'tax_minor' => 'integer',
            'financing_minor' => 'integer',
            'refunded_minor' => 'integer',
            'chargeback_minor' => 'integer',
            'net_minor' => 'integer',
            'settlement_date' => 'immutable_date',
            'reconciliation_state' => SettlementReconciliationState::class,
        ];
    }

    public function integrationConnection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class);
    }
}
