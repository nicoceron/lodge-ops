<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CancellationPolicyTier extends TenantModel
{
    protected function casts(): array
    {
        return [
            'days_before_arrival' => 'integer',
            'retained_basis_points' => 'integer',
            'minimum_fee_minor' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(CancellationPolicy::class, 'cancellation_policy_id');
    }
}
