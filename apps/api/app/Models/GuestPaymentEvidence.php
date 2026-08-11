<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property CarbonImmutable|null $submitted_at */
class GuestPaymentEvidence extends TenantModel
{
    protected $table = 'guest_payment_evidence';

    protected $hidden = ['storage_path'];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
        ];
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
