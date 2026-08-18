<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $reservation_id
 * @property string $document_hash
 * @property CarbonImmutable $acknowledged_at
 * @property-read GuestPortalDocument $document
 * @property-read Guest $guest
 */
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

    /** @return BelongsTo<Guest, $this> */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    /** @return BelongsTo<GuestPortalDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(GuestPortalDocument::class, 'document_id');
    }
}
