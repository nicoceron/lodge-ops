<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxRule extends TenantModel
{
    protected function casts(): array
    {
        return [
            'percentage_basis_points' => 'integer',
            'fixed_amount_minor' => 'integer',
            'is_inclusive' => 'boolean',
            'active_from' => 'immutable_date',
            'active_until' => 'immutable_date',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
