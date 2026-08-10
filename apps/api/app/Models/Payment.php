<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends TenantModel
{
    protected function casts(): array
    {
        return ['status' => PaymentStatus::class, 'amount_minor' => 'integer', 'processed_at' => 'immutable_datetime', 'metadata' => 'array'];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
