<?php

namespace App\Http\Resources;

use App\Models\CashShift;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CashShift */
class CashShiftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'property_id' => $this->property_id,
            'cashier_id' => $this->cashier_id,
            'currency' => $this->currency,
            'state' => $this->state->value,
            'opening_float_minor' => $this->opening_float_minor,
            'current_expected_minor' => $this->state->value === 'open' ? $this->currentExpectedMinor() : $this->expected_cash_minor,
            'expected_cash_minor' => $this->expected_cash_minor,
            'counted_cash_minor' => $this->counted_cash_minor,
            'variance_minor' => $this->variance_minor,
            'business_date' => $this->business_date,
            'opened_at' => $this->opened_at,
            'closed_at' => $this->closed_at,
            'approved_at' => $this->approved_at,
            'movements' => $this->whenLoaded('movements', fn () => $this->movements->map(fn ($movement) => [
                'id' => $movement->id,
                'type' => $movement->type->value,
                'amount_minor' => $movement->amount_minor,
                'currency' => $movement->currency,
                'reason' => $movement->reason,
                'occurred_at' => $movement->occurred_at,
                'reverses_movement_id' => $movement->reverses_movement_id,
            ])),
        ];
    }
}
