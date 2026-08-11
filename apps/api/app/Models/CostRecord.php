<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable $occurred_at
 * @property string $currency
 * @property int $amount_minor
 * @property-read Reservation|null $reservation
 * @property-read Program|null $program
 */
class CostRecord extends TenantModel
{
    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'occurred_at' => 'immutable_datetime', 'metadata' => 'array'];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function staffUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }
}
