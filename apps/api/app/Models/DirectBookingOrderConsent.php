<?php

namespace App\Models;

use App\Enums\DirectBookingPublicationKind;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property DirectBookingPublicationKind $kind
 * @property string $publication_id
 * @property int $publication_version
 * @property string $publication_checksum
 * @property bool $accepted
 */
class DirectBookingOrderConsent extends TenantModel
{
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Consent snapshots are immutable.'));
        static::deleting(fn () => throw new LogicException('Consent snapshots are immutable.'));
    }

    protected $hidden = ['ip_prefix_hash'];

    protected function casts(): array
    {
        return [
            'kind' => DirectBookingPublicationKind::class,
            'publication_version' => 'integer',
            'accepted' => 'boolean',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(DirectBookingOrder::class, 'direct_booking_order_id');
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(DirectBookingPublication::class);
    }
}
