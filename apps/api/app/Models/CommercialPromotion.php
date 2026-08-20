<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $property_id
 * @property string $name
 * @property string $public_label
 * @property int $version
 * @property string $state
 * @property string $currency
 * @property string $discount_type
 * @property int|null $percentage_basis_points
 * @property int|null $fixed_amount_minor
 * @property array<string, mixed>|null $applicability
 * @property int|null $usage_limit
 * @property int|null $per_guest_limit
 * @property int|null $per_session_limit
 * @property int|null $budget_minor
 * @property bool $requires_code
 * @property bool $exclusive
 * @property string|null $stacking_group
 * @property int $priority
 * @property bool $reinstate_on_cancel
 */
class CommercialPromotion extends TenantModel
{
    protected static function booted(): void
    {
        static::updating(function (self $promotion): void {
            if ($promotion->getOriginal('state') === 'published') {
                $allowed = ['state', 'retired_at', 'updated_at'];
                if (array_diff(array_keys($promotion->getDirty()), $allowed) !== []) {
                    throw new LogicException('Published promotion versions are immutable; copy a new version.');
                }
            }
        });
        static::deleting(fn (self $promotion) => $promotion->state === 'draft'
            ?: throw new LogicException('Published promotion versions cannot be deleted.'));
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'percentage_basis_points' => 'integer',
            'fixed_amount_minor' => 'integer',
            'valid_from' => 'immutable_date',
            'valid_until' => 'immutable_date',
            'applicability' => 'array',
            'usage_limit' => 'integer',
            'per_guest_limit' => 'integer',
            'per_session_limit' => 'integer',
            'budget_minor' => 'integer',
            'requires_code' => 'boolean',
            'exclusive' => 'boolean',
            'priority' => 'integer',
            'reinstate_on_cancel' => 'boolean',
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

    public function approvalOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approval_owner_id');
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }
}
