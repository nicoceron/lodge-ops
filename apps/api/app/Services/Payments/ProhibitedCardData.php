<?php

namespace App\Services\Payments;

use App\Enums\MembershipRole;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

final class ProhibitedCardData
{
    /** @param array<string, string|null> $fields */
    public function assertSafe(array $fields, bool $falsePositiveConfirmed, User $actor): void
    {
        foreach ($fields as $name => $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            if (preg_match('/(?:^|\D)(?:0[1-9]|1[0-2])[\/-]\d{2,4}(?:\D|$)/', $value)
                || preg_match('/(?:cvv|cvc|security\s*code|pin|track\s*[12]|;\d{12,19}=)/i', $value)) {
                throw ValidationException::withMessages([$name => 'Do not enter expiry, verification code, PIN, or card-track data in Inn.']);
            }
            preg_match_all('/(?<!\d)(?:\d[ -]?){13,19}(?!\d)/', $value, $matches);
            foreach ($matches[0] as $candidate) {
                $digits = preg_replace('/\D/', '', $candidate) ?? '';
                if ($this->luhn($digits) && ! $this->mayOverride($falsePositiveConfirmed, $actor, $name)) {
                    throw ValidationException::withMessages([$name => 'This value resembles a card number. Enter only a receipt-safe authorization or batch reference. Finance may confirm a documented false positive.']);
                }
            }
        }
    }

    private function mayOverride(bool $confirmed, User $actor, string $field): bool
    {
        $role = app(TenantContext::class)->membership()?->role;

        return $confirmed && in_array($role, [MembershipRole::Administrator, MembershipRole::Manager, MembershipRole::Finance], true)
            && in_array($field, ['transaction_reference', 'authorization_reference', 'batch_reference'], true);
    }

    private function luhn(string $digits): bool
    {
        if (strlen($digits) < 13 || strlen($digits) > 19 || preg_match('/^(\d)\1+$/', $digits)) {
            return false;
        }
        $sum = 0;
        $parity = strlen($digits) % 2;
        foreach (str_split($digits) as $index => $digit) {
            $number = (int) $digit;
            if ($index % 2 === $parity && ($number *= 2) > 9) {
                $number -= 9;
            }
            $sum += $number;
        }

        return $sum % 10 === 0;
    }
}
