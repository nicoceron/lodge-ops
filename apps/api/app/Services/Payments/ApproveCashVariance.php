<?php

namespace App\Services\Payments;

use App\Enums\CashShiftState;
use App\Models\CashShift;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

final class ApproveCashVariance
{
    public function __construct(private readonly FinancialCommandExecutor $commands, private readonly FrontDeskPaymentGuard $guard) {}

    public function handle(User $actor, CashShift $shift, string $reason, string $idempotencyKey): CashShift
    {
        $tenantId = app(TenantContext::class)->tenant()->id;
        $payload = ['shift_id' => $shift->id, 'reason' => trim($reason)];

        /** @var CashShift $result */
        $result = $this->commands->run($tenantId, self::class, $idempotencyKey, $payload, function () use ($actor, $shift, $reason): CashShift {
            $locked = CashShift::query()->lockForUpdate()->findOrFail($shift->id);
            $this->guard->resolveException($actor, $locked->property_id);
            if ($locked->state === CashShiftState::Closed && $locked->approved_at !== null) {
                return $locked;
            }
            if ($locked->state !== CashShiftState::VarianceReview || trim($reason) === '') {
                throw ValidationException::withMessages(['cash_shift' => 'A pending variance and an approval reason are required.']);
            }
            $locked->update(['state' => CashShiftState::Closed, 'approved_by' => $actor->id, 'approved_at' => now(), 'approval_reason' => trim($reason)]);

            return $locked->fresh();
        });

        return $result;
    }
}
