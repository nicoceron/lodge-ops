<?php

namespace App\Models;

use App\Enums\ResourceKind;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property ResourceKind $kind
 * @property string $name
 * @property string $slug
 * @property bool $counts_as_stay
 * @property bool $is_active
 * @property int $sort_order
 * @property int $default_capacity
 */
class ResourceCategory extends TenantModel
{
    protected static function booted(): void
    {
        static::saving(function (ResourceCategory $category): void {
            $category->slug = str($category->slug ?: $category->name)->slug()->toString();
            if ($category->slug === '') {
                throw new LogicException('A resource category needs a slug.');
            }
            if (! Property::query()->whereKey($category->property_id)->exists()) {
                throw new LogicException('A resource category needs a property in the current tenant.');
            }
            if ($category->counts_as_stay && $category->kind !== ResourceKind::Place) {
                throw new LogicException('Only place categories can count as a stay.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'kind' => ResourceKind::class,
            'counts_as_stay' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'default_capacity' => 'integer',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function resources(): HasMany
    {
        return $this->hasMany(Resource::class);
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(ProgramResourceRequirement::class);
    }
}
