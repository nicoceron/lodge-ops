<?php

namespace App\Services\Payments;

use App\Enums\CashMovementType;
use App\Enums\CashShiftState;
use App\Models\CashShift;
use App\Models\CashShiftMovement;
use App\Models\Property;
use App\Models\User;
use App\Services\Documents\CanonicalJson;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

final class OpenCashShift
{
    public function __construct(
        private readonly FinancialCommandExecutor $commands,
        private readonly FrontDeskPaymentGuard $guard,
        private readonly CanonicalJson $canonical,
    ) {}

    public function handle(User $actor, string $propertyId, string $currency, int $openingFloatMinor, string $idempotencyKey): CashShift
    {
        $tenantId = app(TenantContext::class)->tenant()->id;
        $payload = compact('propertyId', 'currency', 'openingFloatMinor', 'idempotencyKey');

        /** @var CashShift $shift */
        $shift = $this->commands->run($tenantId, self::class, $idempotencyKey, $payload, function () use ($actor, $propertyId, $currency, $openingFloatMinor, $idempotencyKey, $payload): CashShift {
            $this->guard->operateCash($actor, $propertyId);
            $property = Property::query()->whereKey($propertyId)->lockForUpdate()->firstOrFail();
            $currency = strtoupper(trim($currency));
            if (! preg_match('/^[A-Z]{3}$/', $currency) || $openingFloatMinor < 0) {
                throw ValidationException::withMessages(['opening_float_minor' => 'Opening float must be a non-negative integer with a three-letter currency.']);
            }
            if (CashShift::query()->where('property_id', $propertyId)->where('cashier_id', $actor->id)
                ->where('currency', $currency)->where('state', CashShiftState::Open->value)->exists()) {
                throw ValidationException::withMessages(['cash_shift' => 'This cashier already has an open shift for the property and currency.']);
            }
            $openedAt = now();
            $checksum = $this->canonical->checksum($payload);
            $shift = CashShift::query()->create([
                'property_id' => $propertyId,
                'cashier_id' => $actor->id,
                'currency' => $currency,
                'state' => CashShiftState::Open,
                'opening_float_minor' => $openingFloatMinor,
                'business_date' => $openedAt->copy()->setTimezone($property->timezone)->toDateString(),
                'opened_at' => $openedAt,
                'command_key' => $idempotencyKey,
                'command_checksum' => $checksum,
            ]);
            CashShiftMovement::query()->create([
                'property_id' => $propertyId,
                'cash_shift_id' => $shift->id,
                'type' => CashMovementType::OpeningFloat,
                'amount_minor' => $openingFloatMinor,
                'currency' => $currency,
                'reason' => 'Opening float',
                'recorded_by' => $actor->id,
                'occurred_at' => $openedAt,
                'command_key' => 'opening:'.$idempotencyKey,
                'command_checksum' => $checksum,
            ]);

            return $shift;
        });

        return $shift;
    }
}
