<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property-read Property $property
 */
class CalendarFeed extends TenantModel
{
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'is_active' => 'boolean',
            'last_accessed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (CalendarFeed $feed): void {
            $resource = Resource::query()->find($feed->resource_id);
            if ($resource === null || $resource->property_id !== $feed->property_id) {
                throw new LogicException('The calendar feed resource must belong to its property and tenant.');
            }
            if (filled($feed->token)) {
                $feed->token_hash = hash('sha256', $feed->token);
            }
        });
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }
}
