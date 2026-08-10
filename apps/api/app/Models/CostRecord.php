<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
