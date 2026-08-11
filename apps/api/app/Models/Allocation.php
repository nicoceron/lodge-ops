<?php

namespace App\Models;

use App\Enums\AllocationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property AllocationStatus $status
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property-read \App\Models\Resource|null $resource
 */
class Allocation extends TenantModel
{
    protected static function booted(): void
    {
        static::saving(function (Allocation $allocation): void {
            $reservation = Reservation::query()->find($allocation->reservation_id);
            if ($reservation === null || ($allocation->resource_id === null && $allocation->service_occurrence_id === null)) {
                throw new LogicException('An allocation requires a tenant reservation and a target.');
            }
            if ($allocation->resource_id !== null && ! Resource::query()
                ->whereKey($allocation->resource_id)
                ->where('property_id', $reservation->property_id)
                ->exists()) {
                throw new LogicException('The allocation resource must belong to the reservation property and tenant.');
            }
            if ($allocation->service_occurrence_id !== null && ! ServiceOccurrence::query()
                ->whereKey($allocation->service_occurrence_id)
                ->where('property_id', $reservation->property_id)
                ->exists()) {
                throw new LogicException('The allocation occurrence must belong to the reservation property and tenant.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => AllocationStatus::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'quantity' => 'integer',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    public function serviceOccurrence(): BelongsTo
    {
        return $this->belongsTo(ServiceOccurrence::class);
    }
}
