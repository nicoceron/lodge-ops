<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FAQRCode\Google2FA;

class MultiFactorAuthenticationService
{
    public function __construct(private readonly Google2FA $google2FA) {}

    public function enabled(User $user): bool
    {
        return filled($user->getAppAuthenticationSecret());
    }

    public function verify(User $user, ?string $code, ?string $recoveryCode): bool
    {
        if (! $this->enabled($user)) {
            return true;
        }
        if (filled($recoveryCode)) {
            return $this->consumeRecoveryCode($user, $recoveryCode);
        }
        if (! is_string($code) || preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        $secret = $user->getAppAuthenticationSecret();
        $cacheKey = 'auth.mfa.timestep.'.hash('sha256', $user->id.':'.$secret);
        $verify = function () use ($cacheKey, $code, $secret): bool {
            $timestamp = $this->google2FA->verifyKeyNewer($secret, $code, Cache::get($cacheKey), 1);
            if ($timestamp === false) {
                return false;
            }
            Cache::put($cacheKey, $timestamp === true ? $this->google2FA->getTimestamp() : $timestamp, now()->addMinutes(2));

            return true;
        };

        return Cache::getStore() instanceof LockProvider
            ? Cache::lock("{$cacheKey}.lock", 10)->block(10, $verify)
            : $verify();
    }

    private function consumeRecoveryCode(User $user, string $recoveryCode): bool
    {
        return DB::transaction(function () use ($user, $recoveryCode): bool {
            /** @var User|null $locked */
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->first();
            if ($locked === null) {
                return false;
            }
            $remaining = [];
            $valid = false;
            foreach ($locked->getAppAuthenticationRecoveryCodes() ?? [] as $hash) {
                if (! $valid && Hash::check($recoveryCode, $hash)) {
                    $valid = true;
                } else {
                    $remaining[] = $hash;
                }
            }
            if ($valid) {
                $locked->saveAppAuthenticationRecoveryCodes($remaining);
            }

            return $valid;
        });
    }
}
