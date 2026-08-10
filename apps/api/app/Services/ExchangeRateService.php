<?php

namespace App\Services;

use App\Models\ExchangeRate;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;

class ExchangeRateService
{
    public function snapshot(string $baseCurrency, string $quoteCurrency, string $rate, string $source, \DateTimeInterface $effectiveAt): ExchangeRate
    {
        $decimal = BigDecimal::of($rate);
        if ($decimal->isLessThanOrEqualTo(0)) {
            throw new DomainException('Exchange rate must be positive.');
        }

        return ExchangeRate::query()->create([
            'base_currency' => strtoupper($baseCurrency),
            'quote_currency' => strtoupper($quoteCurrency),
            'rate' => $decimal->toScale(10, RoundingMode::HalfUp)->__toString(),
            'source' => $source,
            'effective_at' => $effectiveAt,
        ]);
    }

    public function convertMinor(int $amountMinor, ExchangeRate $snapshot): int
    {
        return BigDecimal::of($amountMinor)
            ->multipliedBy($snapshot->rate)
            ->toScale(0, RoundingMode::HalfUp)
            ->toInt();
    }
}
