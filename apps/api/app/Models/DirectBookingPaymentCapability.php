<?php

namespace App\Models;

use App\Enums\DirectBookingPaymentMethod;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property DirectBookingPaymentMethod $method
 * @property string $currency
 * @property bool $is_enabled
 * @property-read IntegrationConnection|null $providerConnection
 * @property-read Collection<int, DirectBookingPaymentInstruction> $localizedInstructions
 */
class DirectBookingPaymentCapability extends TenantModel
{
    protected static function booted(): void
    {
        static::saving(function (self $capability): void {
            $capability->currency = strtoupper($capability->currency);
            if ($capability->instructions_publication_id !== null && ! DirectBookingPublication::query()
                ->whereKey($capability->instructions_publication_id)
                ->where('property_id', $capability->property_id)
                ->exists()) {
                throw new LogicException('Payment instructions must belong to the capability property and tenant.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'method' => DirectBookingPaymentMethod::class,
            'is_enabled' => 'boolean',
            'public_configuration' => 'array',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function providerConnection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'provider_connection_id');
    }

    public function instructionsPublication(): BelongsTo
    {
        return $this->belongsTo(DirectBookingPublication::class, 'instructions_publication_id');
    }

    /** @return HasMany<DirectBookingPaymentInstruction, $this> */
    public function localizedInstructions(): HasMany
    {
        return $this->hasMany(DirectBookingPaymentInstruction::class, 'direct_booking_payment_capability_id');
    }
}
