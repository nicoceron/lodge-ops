<?php

namespace App\Models;

use App\Services\DirectBooking\DirectBookingPublicUrl;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property string $property_id
 * @property string $public_slug
 * @property bool $direct_booking_enabled
 * @property string $default_locale
 * @property list<string> $supported_locales
 * @property string $default_currency
 * @property list<string> $supported_currencies
 * @property bool $bot_verification_required
 * @property string|null $accessible_fallback_url
 * @property int $session_ttl_minutes
 * @property int $initial_hold_minutes
 * @property int $checkout_extension_minutes
 * @property int $maximum_hold_minutes
 * @property int $retention_days
 * @property-read Property $property
 */
class DirectBookingPropertySetting extends TenantModel
{
    protected static function booted(): void
    {
        static::saving(function (self $setting): void {
            $setting->bot_verification_required ??= true;
            $setting->session_ttl_minutes ??= (int) config('direct-booking.default_session_ttl_minutes', 120);
            $setting->initial_hold_minutes ??= (int) config('direct-booking.initial_hold_minutes', 30);
            $setting->checkout_extension_minutes ??= (int) config('direct-booking.checkout_extension_minutes', 15);
            $setting->maximum_hold_minutes ??= (int) config('direct-booking.maximum_hold_minutes', 45);
            $setting->retention_days ??= (int) config('direct-booking.retention_days', 90);
            $setting->public_slug = str($setting->public_slug)->lower()->slug()->toString();
            $setting->default_currency = strtoupper($setting->default_currency);
            $setting->supported_locales = array_values(array_unique(array_map('strval', $setting->supported_locales ?? [])));
            $setting->supported_currencies = array_values(array_unique(array_map(
                static fn (mixed $currency): string => strtoupper((string) $currency),
                $setting->supported_currencies ?? [],
            )));
            if ($setting->public_slug === '' || ! in_array($setting->default_locale, $setting->supported_locales, true)) {
                throw new LogicException('Direct booking requires a safe slug and a default locale in the supported locale list.');
            }
            if (! in_array($setting->default_currency, $setting->supported_currencies, true)) {
                throw new LogicException('Direct booking requires the default currency in the supported currency list.');
            }
            if ($setting->accessible_fallback_url !== null
                && ! app(DirectBookingPublicUrl::class)->isSafeHttps($setting->accessible_fallback_url)) {
                throw new LogicException('The accessible fallback must be a public HTTPS URL without credentials, query, or fragment.');
            }
            if ($setting->initial_hold_minutes > $setting->maximum_hold_minutes
                || $setting->initial_hold_minutes + $setting->checkout_extension_minutes > $setting->maximum_hold_minutes) {
                throw new LogicException('The checkout hold policy exceeds the configured absolute hold bound.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'direct_booking_enabled' => 'boolean',
            'supported_locales' => 'array',
            'supported_currencies' => 'array',
            'bot_verification_required' => 'boolean',
            'session_ttl_minutes' => 'integer',
            'initial_hold_minutes' => 'integer',
            'checkout_extension_minutes' => 'integer',
            'maximum_hold_minutes' => 'integer',
            'retention_days' => 'integer',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function publications(): HasMany
    {
        return $this->hasMany(DirectBookingPublication::class, 'property_id', 'property_id');
    }

    public function paymentCapabilities(): HasMany
    {
        return $this->hasMany(DirectBookingPaymentCapability::class, 'property_id', 'property_id');
    }
}
