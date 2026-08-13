<?php

namespace App\Models;

use App\Enums\HousekeepingStatus;
use App\Enums\ResourceKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property string $property_id
 * @property string $category_id
 * @property int $capacity
 * @property int $recent_assignments
 * @property bool $is_active
 * @property HousekeepingStatus|null $housekeeping_status
 * @property CarbonImmutable|null $housekeeping_updated_at
 * @property int|null $housekeeping_updated_by
 * @property array<string, mixed>|null $attributes
 * @property-read ResourceCategory $category
 * @property-read Property $property
 * @property-read User|null $user
 * @property-read Collection<int, Allocation> $allocations
 * @property-read Collection<int, ResourceBlock> $blocks
 */
class Resource extends TenantModel
{
    protected static function booted(): void
    {
        static::saving(function (Resource $resource): void {
            $category = ResourceCategory::query()->find($resource->category_id);
            if ($category === null) {
                throw new LogicException('A resource needs a category for this property.');
            }
            if ($category->property_id !== $resource->property_id) {
                throw new LogicException('The resource category must belong to the same property.');
            }
            if ($resource->user_id !== null && ! $category->kind->allowsLinkedUser()) {
                throw new LogicException('Only crew resources can be linked to a staff user.');
            }
            if ($resource->housekeeping_status !== null && $category->kind !== ResourceKind::Place) {
                throw new LogicException('Only place resources can have housekeeping state.');
            }
            $categoryPeers = Resource::query()
                ->where('category_id', $resource->category_id)
                ->whereKeyNot($resource->id)
                ->get(['id', 'is_buyout', 'attributes']);
            if ($categoryPeers->isNotEmpty() && $categoryPeers->contains(
                fn (Resource $peer): bool => $peer->isBuyout() !== $resource->isBuyout(),
            )) {
                throw new LogicException('Exclusive buyout and ordinary resources need separate categories.');
            }
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
            'capacity' => 'integer',
            'attributes' => 'array',
            'is_buyout' => 'boolean',
            'is_active' => 'boolean',
            'housekeeping_status' => HousekeepingStatus::class,
            'housekeeping_updated_at' => 'immutable_datetime',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ResourceCategory::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function housekeepingUpdatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'housekeeping_updated_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class);
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(ResourceBlock::class);
    }

    public function kind(): ResourceKind
    {
        return $this->category->kind;
    }

    public function categorySlug(): string
    {
        return $this->category->slug;
    }

    public function categoryName(): string
    {
        return $this->category->name;
    }

    public function countsAsStay(): bool
    {
        return $this->category->counts_as_stay;
    }

    public function isBuyout(): bool
    {
        return $this->is_buyout || (bool) data_get($this->getAttribute('attributes'), 'buyout', false);
    }
}
