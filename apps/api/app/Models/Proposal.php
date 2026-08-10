<?php

namespace App\Models;

use App\Enums\ProposalStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Proposal extends TenantModel
{
    protected function casts(): array
    {
        return ['status' => ProposalStatus::class, 'expires_at' => 'immutable_datetime', 'accepted_at' => 'immutable_datetime', 'total_minor' => 'integer', 'snapshot' => 'array'];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
