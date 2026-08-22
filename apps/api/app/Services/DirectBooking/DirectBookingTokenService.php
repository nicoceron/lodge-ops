<?php

namespace App\Services\DirectBooking;

use App\Enums\DirectBookingOrderState;
use App\Models\DirectBookingOrder;
use App\Models\DirectBookingPropertySetting;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DirectBookingTokenService
{
    /** @param array<string, mixed> $attribution @return array{order: DirectBookingOrder, token: string, recovery_token: string} */
    public function issue(
        DirectBookingPropertySetting $setting,
        string $locale,
        string $currency,
        array $attribution = [],
        ?string $remoteIp = null,
    ): array {
        $token = Str::random(64);
        $recoveryToken = Str::random(64);
        $sessionExpiresAt = now()->addMinutes($setting->session_ttl_minutes ?? (int) config('direct-booking.default_session_ttl_minutes', 120));
        $order = DirectBookingOrder::query()->create([
            'property_id' => $setting->property_id,
            'public_reference' => (string) Str::ulid(),
            'token_hash' => self::hash($token),
            'recovery_token_hash' => self::hash($recoveryToken),
            'locale' => $locale,
            'currency' => strtoupper($currency),
            'state' => DirectBookingOrderState::Started,
            'state_version' => 1,
            'attribution' => app(DirectBookingPrivacy::class)->attribution($attribution),
            'ip_prefix_hash' => app(DirectBookingPrivacy::class)->ipPrefixHash($remoteIp),
            'expires_at' => $sessionExpiresAt,
            'session_expires_at' => $sessionExpiresAt,
            'recovery_expires_at' => now()->addMinutes((int) config('direct-booking.recovery_ttl_minutes', 10080)),
            'retained_until' => now()->addDays($setting->retention_days ?? (int) config('direct-booking.retention_days', 30)),
        ]);

        return ['order' => $order, 'token' => $token, 'recovery_token' => $recoveryToken];
    }

    public function resolve(string $token, ?string $expectedPropertyId = null): DirectBookingOrder
    {
        if (! preg_match('/^[A-Za-z0-9]{64}$/', $token)) {
            throw new AuthenticationException;
        }
        $order = DirectBookingOrder::withoutGlobalScopes()->where('token_hash', self::hash($token))->first();
        if ($order === null || ($expectedPropertyId !== null && ! hash_equals($expectedPropertyId, $order->property_id))
            || $order->revoked_at !== null || $order->session_expires_at->isPast()
            || ! $order->tenant()->where('is_active', true)->exists()) {
            throw new AuthenticationException;
        }

        return $order;
    }

    /**
     * Resolve a session or recovery credential for the read-only status projection.
     *
     * @throws AuthenticationException
     */
    public function resolveForDisplay(string $token, ?string $expectedPropertyId = null): DirectBookingOrder
    {
        if (! preg_match('/^[A-Za-z0-9]{64}$/', $token)) {
            throw new AuthenticationException;
        }
        $hash = self::hash($token);
        $order = DirectBookingOrder::withoutGlobalScopes()
            ->where(fn ($query) => $query->where('token_hash', $hash)->orWhere('recovery_token_hash', $hash))
            ->first();
        $isRecoveryCredential = $order !== null
            && hash_equals((string) $order->getRawOriginal('recovery_token_hash'), $hash);
        if ($order === null
            || ($expectedPropertyId !== null && ! hash_equals($expectedPropertyId, $order->property_id))
            || $order->revoked_at !== null
            || ($isRecoveryCredential && $order->recovery_expires_at?->isPast() !== false)
            || ! $order->tenant()->where('is_active', true)->exists()) {
            throw new AuthenticationException;
        }

        return $order;
    }

    /** @return array{order: DirectBookingOrder, token: string} */
    public function rotate(DirectBookingOrder $order): array
    {
        return DB::transaction(function () use ($order): array {
            $locked = DirectBookingOrder::withoutGlobalScopes()->whereKey($order->id)->lockForUpdate()->first();
            if ($locked === null || $locked->revoked_at !== null || $locked->session_expires_at->isPast()
                || ! $locked->tenant()->where('is_active', true)->exists()) {
                throw new AuthenticationException;
            }
            $token = Str::random(64);
            $sessionExpiresAt = now()->addMinutes((int) config('direct-booking.default_session_ttl_minutes', 120));
            $locked->forceFill([
                'token_hash' => self::hash($token),
                'expires_at' => $sessionExpiresAt,
                'session_expires_at' => $sessionExpiresAt,
                'token_rotated_at' => now(),
            ])->save();

            return ['order' => $locked, 'token' => $token];
        }, 3);
    }

    /** @return array{order: DirectBookingOrder, token: string, recovery_token: string} */
    public function recover(string $recoveryToken, string $expectedPropertyId): array
    {
        if (! preg_match('/^[A-Za-z0-9]{64}$/', $recoveryToken)) {
            throw new AuthenticationException;
        }

        return DB::transaction(function () use ($recoveryToken, $expectedPropertyId): array {
            $locked = DirectBookingOrder::withoutGlobalScopes()
                ->where('recovery_token_hash', self::hash($recoveryToken))
                ->lockForUpdate()
                ->first();
            if ($locked === null || ! hash_equals($expectedPropertyId, $locked->property_id)
                || $locked->revoked_at !== null || $locked->recovery_expires_at?->isPast() !== false
                || ! $locked->tenant()->where('is_active', true)->exists()) {
                throw new AuthenticationException;
            }

            $token = Str::random(64);
            $nextRecoveryToken = Str::random(64);
            $sessionExpiresAt = now()->addMinutes((int) config('direct-booking.default_session_ttl_minutes', 120));
            $locked->forceFill([
                'token_hash' => self::hash($token),
                'recovery_token_hash' => self::hash($nextRecoveryToken),
                'expires_at' => $sessionExpiresAt,
                'session_expires_at' => $sessionExpiresAt,
                'recovery_expires_at' => now()->addMinutes((int) config('direct-booking.recovery_ttl_minutes', 10080)),
                'token_rotated_at' => now(),
            ])->save();

            return ['order' => $locked, 'token' => $token, 'recovery_token' => $nextRecoveryToken];
        }, 3);
    }

    public function revoke(DirectBookingOrder $order): void
    {
        DB::transaction(function () use ($order): void {
            $locked = DirectBookingOrder::withoutGlobalScopes()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->revoked_at === null) {
                $locked->forceFill(['revoked_at' => now()])->save();
            }
        }, 3);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
