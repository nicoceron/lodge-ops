<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DepositPolicy extends TenantModel
{
    protected function casts(): array
    {
        return [
            'percentage_basis_points' => 'integer',
            'fixed_amount_minor' => 'integer',
            'deposit_due_offset_days' => 'integer',
            'balance_due_offset_days' => 'integer',
            'confirmation_requires_payment' => 'boolean',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function ratePlans(): HasMany
    {
        return $this->hasMany(RatePlan::class);
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'requirement_type' => $this->requirement_type,
            'percentage_basis_points' => $this->percentage_basis_points,
            'fixed_amount_minor' => $this->fixed_amount_minor,
            'deposit_due_offset_days' => $this->deposit_due_offset_days,
            'balance_due_offset_days' => $this->balance_due_offset_days,
            'confirmation_requires_payment' => $this->confirmation_requires_payment,
        ];
    }
}
