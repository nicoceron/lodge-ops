<?php

namespace App\Models;

use App\Enums\DirectBookingPaymentMethod;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property DirectBookingPaymentMethod $method
 * @property string $currency
 * @property bool $is_enabled
 * @property-read IntegrationConnection|null $providerConnection
 */
class DirectBookingPaymentCapability extends TenantModel
{
    protected static function booted(): void
    {
        static::saving(function (self $capability): void {
            $capability->currency = strtoupper($capability->currency);
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
}
