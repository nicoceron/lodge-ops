<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\Reservation;

final class MoneyCalculator
{
    public function lineAmount(int $unitAmountMinor, int $quantityThousandths): int
    {
        $product = $unitAmountMinor * $quantityThousandths;
        $rounded = intdiv(abs($product) + 500, 1000);

        return $product < 0 ? -$rounded : $rounded;
    }

    /** @param iterable<int> $amounts */
    public function sum(iterable $amounts): int
    {
        $total = 0;

        foreach ($amounts as $amount) {
            $total += $amount;
        }

        return $total;
    }

    public function reservationBalance(Reservation $reservation): int
    {
        $paid = (int) $reservation->payments()
            ->where('status', PaymentStatus::Succeeded)
            ->sum('amount_minor');

        return $reservation->total_minor - $paid;
    }
}
