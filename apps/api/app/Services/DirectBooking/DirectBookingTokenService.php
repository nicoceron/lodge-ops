<?php

namespace App\Services\DirectBooking;

use App\Models\DirectBookingOrder;
use App\Models\DirectBookingPropertySetting;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DirectBookingTokenService
{
    /** @param array<string, mixed> $attribution @return array{order: DirectBookingOrder, token: string} */
    public function issue(
        DirectBookingPropertySetting $setting,
        string $locale,
        string $currency,
        array $attribution = [],
        ?string $remoteIp = null,
    ): array {
        $token = Str::random(64);
        $order = DirectBookingOrder::query()->create([
            'property_id' => $setting->property_id,
            'public_reference' => (string) Str::ulid(),
            'token_hash' => self::hash($token),
            'locale' => $locale,
            'currency' => strtoupper($currency),
            'attribution' => app(DirectBookingPrivacy::class)->attribution($attribution),
            'ip_prefix_hash' => app(DirectBookingPrivacy::class)->ipPrefixHash($remoteIp),
            'expires_at' => now()->addMinutes($setting->session_ttl_minutes ?? (int) config('direct-booking.default_session_ttl_minutes', 120)),
            'retained_until' => now()->addDays($setting->retention_days ?? (int) config('direct-booking.retention_days', 30)),
        ]);

        return ['order' => $order, 'token' => $token];
    }

    public function resolve(string $token, ?string $expectedPropertyId = null): DirectBookingOrder
    {
        if (! preg_match('/^[A-Za-z0-9]{64}$/', $token)) {
            throw new AuthenticationException;
        }
        $order = DirectBookingOrder::withoutGlobalScopes()->where('token_hash', self::hash($token))->first();
        if ($order === null || ($expectedPropertyId !== null && ! hash_equals($expectedPropertyId, $order->property_id))
            || $order->revoked_at !== null || $order->expires_at->isPast()
            || ! $order->tenant()->where('is_active', true)->exists()) {
            throw new AuthenticationException;
        }

        return $order;
    }

    /** @return array{order: DirectBookingOrder, token: string} */
    public function rotate(DirectBookingOrder $order): array
    {
        return DB::transaction(function () use ($order): array {
            $locked = DirectBookingOrder::query()->lockForUpdate()->findOrFail($order->id);
            $token = Str::random(64);
            $locked->forceFill([
                'token_hash' => self::hash($token),
                'token_rotated_at' => now(),
                'revoked_at' => null,
            ])->save();

            return ['order' => $locked, 'token' => $token];
        });
    }

    public function revoke(DirectBookingOrder $order): void
    {
        $order->forceFill(['revoked_at' => now()])->save();
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
