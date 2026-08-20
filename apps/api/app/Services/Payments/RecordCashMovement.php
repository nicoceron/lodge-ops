<?php

namespace App\Services\Payments;

use App\Enums\CashMovementType;
use App\Enums\CashShiftState;
use App\Models\CashShift;
use App\Models\CashShiftMovement;
use App\Models\User;
use App\Services\Documents\CanonicalJson;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

final class RecordCashMovement
{
    public function __construct(
        private readonly FinancialCommandExecutor $commands,
        private readonly FrontDeskPaymentGuard $guard,
        private readonly CanonicalJson $canonical,
    ) {}

    public function handle(User $actor, CashShift $shift, CashMovementType $type, int $amountMinor, string $reason, string $idempotencyKey, ?CashShiftMovement $reverses = null): CashShiftMovement
    {
        $tenantId = app(TenantContext::class)->tenant()->id;
        $payload = ['shift_id' => $shift->id, 'type' => $type, 'amount_minor' => $amountMinor, 'reason' => trim($reason), 'reverses_id' => $reverses?->id];
        $checksum = $this->canonical->checksum($payload);

        /** @var CashShiftMovement $movement */
        $movement = $this->commands->run($tenantId, self::class, $idempotencyKey, $payload, function () use ($actor, $shift, $type, $amountMinor, $reason, $idempotencyKey, $reverses, $checksum): CashShiftMovement {
            $locked = CashShift::query()->lockForUpdate()->findOrFail($shift->id);
            $this->guard->operateCash($actor, $locked->property_id);
            if ($locked->cashier_id !== $actor->id || $locked->state !== CashShiftState::Open) {
                throw ValidationException::withMessages(['cash_shift' => 'Only the active cashier may operate an open shift.']);
            }
            if (! in_array($type, [CashMovementType::PayIn, CashMovementType::PayOut, CashMovementType::Correction], true)) {
                throw ValidationException::withMessages(['type' => 'Only pay-in, pay-out, and immutable correction movements are accepted.']);
            }
            $reason = trim($reason);
            if ($reason === '' || $amountMinor <= 0) {
                throw ValidationException::withMessages(['amount_minor' => 'A positive amount and reason are required.']);
            }
            $signed = $type === CashMovementType::PayOut ? -$amountMinor : $amountMinor;
            if ($type === CashMovementType::PayOut && $amountMinor > $locked->currentExpectedMinor()) {
                throw ValidationException::withMessages(['amount_minor' => 'A pay-out cannot exceed the cash currently expected in the shift.']);
            }
            $reversalId = null;
            if ($type === CashMovementType::Correction) {
                $original = CashShiftMovement::query()->lockForUpdate()->findOrFail($reverses?->id);
                if ($original->cash_shift_id !== $locked->id || $original->type === CashMovementType::Correction) {
                    throw ValidationException::withMessages(['reverses_movement_id' => 'A correction must reverse a non-correction movement on this shift.']);
                }
                if ($amountMinor !== abs($original->amount_minor)) {
                    throw ValidationException::withMessages(['amount_minor' => 'A correction must exactly oppose the selected movement amount.']);
                }
                if (CashShiftMovement::query()->where('reverses_movement_id', $original->id)->exists()) {
                    throw ValidationException::withMessages(['reverses_movement_id' => 'This cash movement already has an opposing correction.']);
                }
                $signed = -$original->amount_minor;
                $reversalId = $original->id;
            }

            return CashShiftMovement::query()->create([
                'property_id' => $locked->property_id,
                'cash_shift_id' => $locked->id,
                'reverses_movement_id' => $reversalId,
                'type' => $type,
                'amount_minor' => $signed,
                'currency' => $locked->currency,
                'reason' => $reason,
                'recorded_by' => $actor->id,
                'occurred_at' => now(),
                'command_key' => $idempotencyKey,
                'command_checksum' => $checksum,
            ]);
        });

        return $movement;
    }
}
