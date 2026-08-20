<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $version
 * @property int|null $amount_minor
 * @property int $default_quantity
 * @property int $maximum_quantity
 * @property int $priority
 * @property bool $is_active
 * @property string $selection_type
 * @property string $quantity_basis
 * @property string $catalog_item_id
 * @property-read CatalogItem $catalogItem
 */
class RatePlanService extends TenantModel
{
    protected static function booted(): void
    {
        $guard = function (self $service): void {
            if (RatePlan::query()->whereKey($service->rate_plan_id)->value('state') === 'published') {
                throw new LogicException('Published rate-plan services are immutable; copy a new plan version.');
            }
        };
        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer', 'amount_minor' => 'integer', 'default_quantity' => 'integer',
            'maximum_quantity' => 'integer', 'priority' => 'integer', 'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<RatePlan, $this> */
    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class);
    }

    /** @return BelongsTo<CatalogItem, $this> */
    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }
}
