<?php

namespace App\Models;

use App\Enums\ResourceType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property ResourceType $type
 * @property int $capacity
 * @property int $recent_assignments
 * @property string $property_id
 * @property bool $is_active
 * @property array<string, mixed>|null $attributes
 * @property-read Collection<int, Allocation> $allocations
 * @property-read Collection<int, ResourceBlock> $blocks
 */
class Resource extends TenantModel
{
    protected static function booted(): void
    {
        static::saving(function (Resource $resource): void {
            if ($resource->user_id !== null && ! Membership::query()
                ->where('user_id', $resource->user_id)
                ->where('is_active', true)
                ->where(function ($query) use ($resource): void {
                    $query->whereNull('property_id')->orWhere('property_id', $resource->property_id);
                })
                ->exists()) {
                throw new LogicException('A linked resource user needs an active membership for this tenant and property.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'type' => ResourceType::class,
            'capacity' => 'integer',
            'attributes' => 'array',
            'is_buyout' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class);
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(ResourceBlock::class);
    }

    public function isBuyout(): bool
    {
        return $this->is_buyout || (bool) data_get($this->getAttribute('attributes'), 'buyout', false);
    }
}
