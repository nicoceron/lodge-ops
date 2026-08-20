<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/** @property array<string, mixed>|null $operational_preferences */
class ReservationGuest extends Pivot
{
    use BelongsToTenant, HasUuid;

    public $incrementing = false;

    protected $table = 'reservation_guests';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'operational_preferences' => 'array'];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }
}
