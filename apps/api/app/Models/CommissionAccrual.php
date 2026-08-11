<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $created_at
 * @property string $currency
 * @property int $amount_minor
 * @property-read Reservation $reservation
 */
class CommissionAccrual extends TenantModel
{
    protected function casts(): array
    {
        return [
            'rate_basis_points' => 'integer',
            'base_amount_minor' => 'integer',
            'amount_minor' => 'integer',
            'paid_at' => 'immutable_datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
