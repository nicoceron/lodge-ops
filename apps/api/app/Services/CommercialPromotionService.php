<?php

namespace App\Services;

use App\Models\BookingQuote;
use App\Models\CommercialPromotion;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Models\VoucherRedemptionEvent;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class CommercialPromotionService
{
    public function __construct(private readonly VoucherCodeCanonicalizer $codes) {}

    public function issueVoucher(CommercialPromotion $promotion, string $code, array $overrides = []): Voucher
    {
        if ($promotion->state !== 'published' || ! $promotion->requires_code) {
            throw ValidationException::withMessages(['promotion' => 'Only a published code promotion may issue vouchers.']);
        }

        return Voucher::query()->create([
            'property_id' => $promotion->property_id,
            'commercial_promotion_id' => $promotion->id,
            'code_hash' => $this->codes->hash($promotion->tenant_id, $code),
            'public_label' => $overrides['public_label'] ?? $promotion->public_label,
            'state' => 'active',
            'usage_limit' => $overrides['usage_limit'] ?? $promotion->usage_limit,
            'per_guest_limit' => $overrides['per_guest_limit'] ?? $promotion->per_guest_limit,
            'per_session_limit' => $overrides['per_session_limit'] ?? $promotion->per_session_limit,
            'budget_minor' => $overrides['budget_minor'] ?? $promotion->budget_minor,
            'valid_from' => $overrides['valid_from'] ?? null,
            'valid_until' => $overrides['valid_until'] ?? null,
        ]);
    }

    /** @param array<string, mixed> $input @return Collection<int, CommercialPromotion> */
    public function eligible(array $input, string $currency, CarbonImmutable $businessDate): Collection
    {
        $propertyId = (string) $input['property_id'];
        $promotions = CommercialPromotion::query()->with('vouchers')
            ->where('state', 'published')->where('currency', strtoupper($currency))
            ->where(fn ($query) => $query->whereNull('property_id')->orWhere('property_id', $propertyId))
            ->where(fn ($query) => $query->whereNull('valid_from')->orWhere('valid_from', '<=', $businessDate->toDateString()))
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>=', $businessDate->toDateString()))
            ->orderByDesc('priority')->orderBy('id')->get();

        $voucher = null;
        if (! empty($input['voucher_code'])) {
            try {
                $hash = $this->codes->hash(app(TenantContext::class)->id(), (string) $input['voucher_code']);
            } catch (ValidationException) {
                throw $this->genericVoucherError();
            }
            $voucher = Voucher::query()->with('promotion')->where('code_hash', $hash)
                ->where('state', 'active')
                ->where(fn ($query) => $query->whereNull('property_id')->orWhere('property_id', $propertyId))
                ->first();
            if ($voucher === null || ($voucher->valid_from?->isFuture() ?? false) || ($voucher->valid_until?->isPast() ?? false)) {
                throw $this->genericVoucherError();
            }
        } elseif (! empty($input['voucher_id']) && ! empty($input['amendment_of_reservation_id'])) {
            $voucher = Voucher::query()->with('promotion')->whereKey($input['voucher_id'])
                ->whereHas('redemptions', fn ($query) => $query->where('reservation_id', $input['amendment_of_reservation_id']))
                ->first();
            if ($voucher === null) {
                throw $this->genericVoucherError();
            }
        }

        $sessionHash = $this->sessionHash($input['promotion_session_id'] ?? null);
        $selected = $promotions->filter(function (CommercialPromotion $promotion) use ($voucher, $input): bool {
            if ($promotion->requires_code && $voucher?->commercial_promotion_id !== $promotion->id) {
                return false;
            }
            $scope = $promotion->applicability ?? [];
            foreach (['rate_plan_id', 'resource_category_id', 'program_id'] as $field) {
                $allowed = array_values(array_filter((array) ($scope[$field.'s'] ?? [])));
                if ($allowed !== [] && ! in_array($input[$field] ?? null, $allowed, true)) {
                    return false;
                }
            }
            $nights = (int) ($input['night_count'] ?? 0);

            return $nights >= (int) ($scope['minimum_stay'] ?? 0)
                && (($scope['maximum_stay'] ?? null) === null || $nights <= (int) $scope['maximum_stay']);
        });

        $exclusive = $selected->firstWhere('exclusive', true);
        if ($exclusive !== null) {
            $selected = new Collection([$exclusive]);
        } else {
            $seen = [];
            $selected = $selected->filter(function (CommercialPromotion $promotion) use (&$seen): bool {
                if ($promotion->stacking_group === null) {
                    return true;
                }
                if (isset($seen[$promotion->stacking_group])) {
                    return false;
                }
                $seen[$promotion->stacking_group] = true;

                return true;
            });
        }

        return $selected->each(function (CommercialPromotion $promotion) use ($voucher, $sessionHash): void {
            $promotion->setAttribute('resolved_voucher_id', $promotion->requires_code ? $voucher?->id : null);
            $promotion->setAttribute('resolved_session_hash', $promotion->requires_code ? $sessionHash : null);
        })->values();
    }

    /** @return array{discount_minor:int, lines:list<array<string,mixed>>, promotion_snapshot:list<array<string,mixed>>, voucher_id:?string, session_hash:?string} */
    public function calculate(Collection $promotions, int $eligibleMinor): array
    {
        $remaining = max(0, $eligibleMinor);
        $discount = 0;
        $lines = [];
        $snapshot = [];
        $voucherId = null;
        $sessionHash = null;
        foreach ($promotions as $index => $promotion) {
            $amount = $promotion->discount_type === 'fixed'
                ? min($remaining, (int) $promotion->fixed_amount_minor)
                : min($remaining, $this->percentage($remaining, (int) $promotion->percentage_basis_points));
            if ($amount <= 0) {
                continue;
            }
            $voucherId ??= $promotion->getAttribute('resolved_voucher_id');
            $sessionHash ??= $promotion->getAttribute('resolved_session_hash');
            $remaining -= $amount;
            $discount += $amount;
            $facts = [
                'promotion_id' => $promotion->id, 'promotion_version' => $promotion->version,
                'voucher_id' => $promotion->getAttribute('resolved_voucher_id'), 'priority' => $promotion->priority,
                'exclusive' => $promotion->exclusive, 'stacking_group' => $promotion->stacking_group,
            ];
            $lines[] = [
                'type' => 'discount', 'description' => $promotion->public_label, 'basis' => 'eligible_subtotal',
                'calculation_order' => 300 + $index, 'service_on' => null, 'quantity_thousandths' => 1000,
                'unit_amount_minor' => -$amount, 'pre_total_minor' => $remaining + $amount,
                'net_amount_minor' => -$amount, 'tax_amount_minor' => 0, 'gross_amount_minor' => -$amount,
                'post_total_minor' => $remaining, 'rounding_mode' => 'half_up',
                'explanation' => $promotion->discount_type === 'fixed'
                    ? 'Fixed promotion amount, capped at the eligible subtotal.'
                    : "{$promotion->percentage_basis_points} basis points applied after services.",
                'metadata' => $facts,
            ];
            $snapshot[] = $facts + [
                'public_label' => $promotion->public_label, 'discount_type' => $promotion->discount_type,
                'percentage_basis_points' => $promotion->percentage_basis_points,
                'fixed_amount_minor' => $promotion->fixed_amount_minor, 'discount_minor' => $amount,
            ];
        }

        return [
            'discount_minor' => $discount,
            'lines' => $lines,
            'promotion_snapshot' => $snapshot,
            'voucher_id' => $voucherId,
            'session_hash' => $sessionHash,
        ];
    }

    public function reserveForCommit(BookingQuote $quote, Reservation $reservation, ?Guest $guest): ?VoucherRedemption
    {
        $voucherId = data_get($quote->calculation_snapshot, 'voucher_id');
        if (! is_string($voucherId) || $voucherId === '') {
            return null;
        }
        $existing = VoucherRedemption::query()->where('booking_quote_id', $quote->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $voucher = Voucher::query()->with('promotion')->lockForUpdate()->findOrFail($voucherId);
        if ($voucher->state !== 'active' || ($voucher->property_id !== null && $voucher->property_id !== $reservation->property_id)) {
            throw $this->genericVoucherError();
        }
        $discount = (int) $quote->discount_minor;
        $active = VoucherRedemption::query()->where('voucher_id', $voucher->id)->whereIn('state', ['reserved', 'confirmed']);
        $usage = (clone $active)->count();
        $spent = (int) (clone $active)->sum('discount_minor');
        $limit = $voucher->usage_limit ?? $voucher->promotion->usage_limit;
        $budget = $voucher->budget_minor ?? $voucher->promotion->budget_minor;
        $guestLimit = $voucher->per_guest_limit ?? $voucher->promotion->per_guest_limit;
        $sessionLimit = $voucher->per_session_limit ?? $voucher->promotion->per_session_limit;
        $sessionHash = data_get($quote->calculation_snapshot, 'promotion_session_hash');
        if (($limit !== null && $usage >= $limit) || ($budget !== null && $spent + $discount > $budget)
            || ($guest?->id !== null && $guestLimit !== null && (clone $active)->where('guest_id', $guest->id)->count() >= $guestLimit)
            || ($sessionLimit !== null && (! is_string($sessionHash)
                || (clone $active)->where('session_key_hash', $sessionHash)->count() >= $sessionLimit))) {
            throw $this->genericVoucherError();
        }

        $redemption = VoucherRedemption::query()->create([
            'voucher_id' => $voucher->id, 'booking_quote_id' => $quote->id, 'reservation_id' => $reservation->id,
            'guest_id' => $guest?->id, 'session_key_hash' => is_string($sessionHash) ? $sessionHash : null,
            'command_id' => $quote->id, 'state' => 'reserved',
            'currency' => $quote->currency, 'discount_minor' => $discount, 'reserved_at' => now(),
        ]);
        $this->event($redemption, 'reserved', 'Atomic booking hold', ['quote_id' => $quote->id, 'discount_minor' => $discount]);

        return $redemption;
    }

    public function confirm(Reservation $reservation): void
    {
        $redemption = VoucherRedemption::query()->where('reservation_id', $reservation->id)->lockForUpdate()->first();
        if ($redemption === null || $redemption->state === 'confirmed') {
            return;
        }
        if (! in_array($redemption->state, ['reserved', 'reinstated'], true)) {
            throw ValidationException::withMessages(['voucher_code' => 'The promotion can no longer be confirmed.']);
        }
        $redemption->update(['state' => 'confirmed', 'confirmed_at' => now(), 'released_at' => null]);
        $this->event($redemption, 'confirmed', 'Reservation confirmed', []);
    }

    public function release(Reservation $reservation, string $reason, bool $cancellation): void
    {
        $redemption = VoucherRedemption::query()->with('voucher.promotion')->where('reservation_id', $reservation->id)->lockForUpdate()->first();
        if ($redemption === null || $redemption->state === 'released') {
            return;
        }
        $mayReinstate = ! $cancellation || $redemption->voucher->promotion->reinstate_on_cancel;
        $state = $mayReinstate ? 'reinstated' : $redemption->state;
        $redemption->update(['state' => $state, 'released_at' => $mayReinstate ? now() : null]);
        $this->event($redemption, $mayReinstate ? 'reinstated' : 'retained', $reason, ['cancellation' => $cancellation]);
    }

    private function event(VoucherRedemption $redemption, string $type, string $reason, array $facts): void
    {
        VoucherRedemptionEvent::query()->create([
            'voucher_redemption_id' => $redemption->id, 'actor_id' => auth()->id(), 'type' => $type,
            'policy_reason' => $reason, 'facts' => $facts, 'occurred_at' => now(),
        ]);
    }

    private function genericVoucherError(): ValidationException
    {
        return ValidationException::withMessages(['voucher_code' => 'The promotion could not be applied.']);
    }

    private function sessionHash(mixed $sessionId): ?string
    {
        if ($sessionId === null || $sessionId === '') {
            return null;
        }
        if (! is_string($sessionId) || strlen($sessionId) < 16 || strlen($sessionId) > 200) {
            throw $this->genericVoucherError();
        }

        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true) ?: $key;
        }

        return hash_hmac('sha256', app(TenantContext::class)->id()."\0session\0".$sessionId, $key);
    }

    private function percentage(int $amount, int $basisPoints): int
    {
        if ($amount < 0 || $basisPoints < 0 || ($amount !== 0 && $basisPoints > intdiv(PHP_INT_MAX - 5000, $amount))) {
            throw ValidationException::withMessages(['promotion' => 'The promotion amount is outside supported integer-money limits.']);
        }

        return intdiv(($amount * $basisPoints) + 5000, 10000);
    }
}
