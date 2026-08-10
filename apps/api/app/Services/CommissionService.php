<?php

namespace App\Services;

use App\Models\CommissionAccrual;
use App\Models\Reservation;
use DomainException;

class CommissionService
{
    public function accrue(Reservation $reservation, string $payeeType, string $payeeName, int $rateBasisPoints): CommissionAccrual
    {
        if ($rateBasisPoints < 0 || $rateBasisPoints > 10_000) {
            throw new DomainException('Commission rate must be between 0 and 10,000 basis points.');
        }

        $amount = intdiv(($reservation->total_minor * $rateBasisPoints) + 5000, 10_000);

        return CommissionAccrual::query()->create([
            'reservation_id' => $reservation->id,
            'payee_type' => $payeeType,
            'payee_name' => $payeeName,
            'rate_basis_points' => $rateBasisPoints,
            'base_amount_minor' => $reservation->total_minor,
            'amount_minor' => $amount,
            'currency' => $reservation->currency,
            'status' => 'accrued',
        ]);
    }
}
