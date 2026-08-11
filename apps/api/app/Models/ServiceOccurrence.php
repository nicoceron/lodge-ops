<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property string $property_id
 * @property-read Program $program
 * @property-read Collection<int, Allocation> $allocations
 */
class ServiceOccurrence extends TenantModel
{
    protected static function booted(): void
    {
        static::saving(function (ServiceOccurrence $occurrence): void {
            if (! Program::query()->whereKey($occurrence->program_id)->where('property_id', $occurrence->property_id)->exists()) {
                throw new LogicException('The occurrence program must belong to its property and tenant.');
            }
        });
    }

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'capacity' => 'integer', 'is_cancelled' => 'boolean'];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class);
    }
}
