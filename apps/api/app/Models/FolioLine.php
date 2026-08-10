<?php

namespace App\Models;

use App\Enums\FolioLineType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FolioLine extends TenantModel
{
    protected function casts(): array
    {
        return ['type' => FolioLineType::class, 'quantity' => 'decimal:3', 'unit_amount_minor' => 'integer', 'amount_minor' => 'integer', 'posted_at' => 'immutable_datetime', 'metadata' => 'array'];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
