<?php

namespace App\Services;

use App\Models\Guest;
use App\Models\GuestPortalAccessToken;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class GuestPortalTokenService
{
    /** @return array{access: GuestPortalAccessToken, token: string} */
    public function issue(Reservation $reservation, Guest $guest, ?CarbonImmutable $expiresAt = null): array
    {
        $belongsToReservation = $reservation->primary_guest_id === $guest->id
            || $reservation->guests()->whereKey($guest->id)->exists();

        if (! $belongsToReservation) {
            throw new LogicException('Guest portal access can only be issued to a guest on the reservation.');
        }

        $plainToken = Str::random(64);
        $access = GuestPortalAccessToken::query()->create([
            'reservation_id' => $reservation->id,
            'guest_id' => $guest->id,
            'token_hash' => self::hash($plainToken),
            'expires_at' => $expiresAt ?? now()->toImmutable()->addMinutes(config('guest-portal.magic_link_ttl_minutes')),
        ]);

        return ['access' => $access, 'token' => $plainToken];
    }

    /** @return array{access: GuestPortalAccessToken, token: string} */
    public function exchange(string $plainToken): array
    {
        if (strlen($plainToken) < 32 || strlen($plainToken) > 256) {
            throw new AuthenticationException;
        }

        return DB::transaction(function () use ($plainToken): array {
            $access = GuestPortalAccessToken::withoutGlobalScopes()
                ->where('token_hash', self::hash($plainToken))
                ->lockForUpdate()
                ->first();

            if (
                $access === null
                || $access->revoked_at !== null
                || $access->exchanged_at !== null
                || $access->expires_at->isPast()
                || ! $access->tenant()->where('is_active', true)->exists()
            ) {
                throw new AuthenticationException;
            }

            $plainSession = Str::random(64);
            $configuredExpiry = now()->toImmutable()->addMinutes(config('guest-portal.session_ttl_minutes'));
            $sessionExpiry = $configuredExpiry->lessThan($access->expires_at) ? $configuredExpiry : $access->expires_at;

            $access->forceFill([
                'session_hash' => self::hash($plainSession),
                'exchanged_at' => now(),
                'session_expires_at' => $sessionExpiry,
            ])->save();

            return ['access' => $access, 'token' => $plainSession];
        });
    }

    public function revoke(GuestPortalAccessToken $access): void
    {
        $access->forceFill(['revoked_at' => now(), 'session_hash' => null])->save();
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
