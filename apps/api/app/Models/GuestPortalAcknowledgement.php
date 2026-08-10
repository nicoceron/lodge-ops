<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuestPortalAcknowledgement extends TenantModel
{
    protected function casts(): array
    {
        return ['acknowledged_at' => 'immutable_datetime'];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(GuestPortalDocument::class, 'document_id');
    }
}
