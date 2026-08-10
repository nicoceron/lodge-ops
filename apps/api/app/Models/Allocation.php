<?php

namespace App\Models;

use App\Enums\AllocationStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Allocation extends TenantModel
{
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
