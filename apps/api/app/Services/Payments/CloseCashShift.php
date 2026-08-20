<?php

namespace App\Services\Payments;

use App\Enums\CashShiftState;
use App\Enums\MembershipRole;
use App\Models\CashShift;
use App\Models\Membership;
use App\Models\User;
use App\Services\Documents\CanonicalJson;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

final class CloseCashShift
{
    public function __construct(
        private readonly FinancialCommandExecutor $commands,
        private readonly FrontDeskPaymentGuard $guard,
        private readonly CanonicalJson $canonical,
    ) {}

    public function handle(User $actor, CashShift $shift, int $countedCashMinor, ?string $reason, string $idempotencyKey, bool $force = false): CashShift
    {
        $tenantId = app(TenantContext::class)->tenant()->id;
        $payload = ['shift_id' => $shift->id, 'counted_cash_minor' => $countedCashMinor, 'reason' => trim((string) $reason), 'force' => $force];
        $canonical = $this->canonical;

        /** @var CashShift $result */
        $result = $this->commands->run($tenantId, self::class, $idempotencyKey, $payload, function () use ($actor, $shift, $countedCashMinor, $reason, $force, $canonical): CashShift {
            $locked = CashShift::query()->lockForUpdate()->findOrFail($shift->id);
            $this->guard->operateCash($actor, $locked->property_id);
            if ($locked->state !== CashShiftState::Open) {
                return $locked;
            }
            if ($countedCashMinor < 0) {
                throw ValidationException::withMessages(['counted_cash_minor' => 'Counted cash cannot be negative.']);
            }
            if ($locked->cashier_id !== $actor->id) {
                $role = app(TenantContext::class)->membership()?->role;
                $staleBefore = now()->subHours(max(1, (int) config('front_desk_tenders.cash_shift_stale_hours', 16)));
                $cashierActive = Membership::query()->where('user_id', $locked->cashier_id)
                    ->where(function ($query) use ($locked): void {
                        $query->whereNull('property_id')->orWhere('property_id', $locked->property_id);
                    })->where('is_active', true)->exists();
                if (! $force || ! in_array($role, [MembershipRole::Administrator, MembershipRole::Manager], true)
                    || ($cashierActive && $locked->opened_at->isAfter($staleBefore))) {
                    throw ValidationException::withMessages(['force' => 'Only an Administrator or Manager may force-close another cashier shift after it is stale or its cashier is disabled.']);
                }
            }
            $movements = $locked->movements()->lockForUpdate()->get(['id', 'amount_minor']);
            $expected = (int) $movements->sum('amount_minor');
            $variance = $countedCashMinor - $expected;
            $reason = trim((string) $reason);
            if ($variance !== 0 && $reason === '') {
                throw ValidationException::withMessages(['reason' => 'A reason is required for a non-zero cash variance.']);
            }
            $threshold = max(0, (int) data_get($locked->property->settings, 'cash_variance_threshold_minor', 0));
            $state = abs($variance) > $threshold ? CashShiftState::VarianceReview : CashShiftState::Closed;
            $calculation = ['shift_id' => $locked->id, 'movement_ids' => $movements->pluck('id')->all(), 'expected_minor' => $expected, 'counted_minor' => $countedCashMinor, 'variance_minor' => $variance];
            $locked->update([
                'state' => $state,
                'expected_cash_minor' => $expected,
                'counted_cash_minor' => $countedCashMinor,
                'variance_minor' => $variance,
                'closed_at' => now(),
                'closed_by' => $actor->id,
                'close_reason' => $reason ?: null,
                'calculation_checksum' => $canonical->checksum($calculation),
            ]);

            return $locked->fresh('movements');
        });

        return $result;
    }
}
