<?php

namespace App\Models;

use App\Enums\DirectBookingErrorCode;
use App\Enums\DirectBookingOrderState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $property_id
 * @property string $public_reference
 * @property DirectBookingOrderState $state
 * @property int $state_version
 * @property DirectBookingErrorCode|null $safe_failure_code
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $held_at
 * @property CarbonImmutable|null $hold_extended_at
 */
class DirectBookingOrder extends TenantModel
{
    protected $hidden = [
        'token_hash', 'guest_contact_encrypted', 'guest_contact_checksum', 'consent_checksum', 'ip_prefix_hash',
    ];

    protected function casts(): array
    {
        return [
            'state' => DirectBookingOrderState::class,
            'state_version' => 'integer',
            'guest_contact_encrypted' => 'encrypted:array',
            'attribution' => 'array',
            'safe_failure_code' => DirectBookingErrorCode::class,
            'expires_at' => 'immutable_datetime',
            'quoted_at' => 'immutable_datetime',
            'held_at' => 'immutable_datetime',
            'hold_extended_at' => 'immutable_datetime',
            'payment_started_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'token_rotated_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'retained_until' => 'immutable_datetime',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function bookingQuote(): BelongsTo
    {
        return $this->belongsTo(BookingQuote::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class);
    }

    public function consents(): HasMany
    {
        return $this->hasMany(DirectBookingOrderConsent::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(DirectBookingOrderEvent::class)->orderBy('sequence');
    }
}
