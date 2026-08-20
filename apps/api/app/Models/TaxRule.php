<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class TaxRule extends TenantModel
{
    protected static function booted(): void
    {
        static::updating(function (self $rule): void {
            if ($rule->getOriginal('state') === 'published') {
                $allowed = ['state', 'retired_at', 'is_active', 'updated_at'];
                if (array_diff(array_keys($rule->getDirty()), $allowed) !== []) {
                    throw new LogicException('Published tax-input versions are immutable; copy a new version.');
                }
            }
        });
        static::deleting(fn (self $rule) => $rule->state === 'draft'
            ?: throw new LogicException('Published tax-input versions cannot be deleted.'));
    }

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
            'version' => 'integer',
            'jurisdiction_inputs' => 'array',
            'published_at' => 'immutable_datetime',
            'retired_at' => 'immutable_datetime',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
