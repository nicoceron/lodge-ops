<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

/**
 * @property string $id
 * @property string $property_id
 * @property string $public_key
 * @property 'category'|'program' $kind
 * @property string|null $resource_category_id
 * @property string|null $program_id
 * @property bool $is_enabled
 * @property-read Collection<int, DirectBookingPublication> $publications
 */
class DirectBookingPublicItem extends TenantModel
{
    protected static function booted(): void
    {
        static::creating(function (self $item): void {
            $item->public_key ??= (string) Str::ulid();
        });
        static::saving(function (self $item): void {
            $valid = ($item->kind === 'category' && $item->resource_category_id !== null && $item->program_id === null)
                || ($item->kind === 'program' && $item->program_id !== null && $item->resource_category_id === null);
            if (! $valid) {
                throw new LogicException('A public item must reference exactly one category or program matching its kind.');
            }
            $subject = $item->kind === 'category'
                ? ResourceCategory::query()->find($item->resource_category_id)
                : Program::query()->find($item->program_id);
            if ($subject === null || $subject->property_id !== $item->property_id) {
                throw new LogicException('A public item subject must belong to the same property and tenant.');
            }
        });
    }

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'sort_order' => 'integer'];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function resourceCategory(): BelongsTo
    {
        return $this->belongsTo(ResourceCategory::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function publications(): HasMany
    {
        return $this->hasMany(DirectBookingPublication::class, 'public_item_id');
    }
}
