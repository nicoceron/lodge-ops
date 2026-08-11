<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $property_id
 * @property string $name
 * @property string|null $display_color
 * @property bool $is_active
 * @property bool $requires_accommodation
 * @property-read Collection<int, ProgramResourceRequirement> $requirements
 * @property-read Collection<int, ProgramTaskTemplate> $taskTemplates
 */
class Program extends TenantModel
{
    protected function casts(): array
    {
        return [
            'default_duration_minutes' => 'integer',
            'capacity' => 'integer',
            'price_minor' => 'integer',
            'requires_accommodation' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(ServiceOccurrence::class);
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(ProgramResourceRequirement::class)->orderBy('sort_order');
    }

    public function taskTemplates(): HasMany
    {
        return $this->hasMany(ProgramTaskTemplate::class)->orderBy('sort_order');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }
}
