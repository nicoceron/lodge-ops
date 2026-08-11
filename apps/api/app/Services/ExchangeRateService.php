<?php

namespace App\Services;

use App\Models\ExchangeRate;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use DateTimeInterface;
use DomainException;

class ExchangeRateService
{
    public function snapshot(
        string $baseCurrency,
        string $quoteCurrency,
        string $rate,
        string $source,
        DateTimeInterface $effectiveAt,
        ?string $propertyId = null,
    ): ExchangeRate {
        $baseCurrency = strtoupper(trim($baseCurrency));
        $quoteCurrency = strtoupper(trim($quoteCurrency));
        $source = trim($source);
        $decimal = BigDecimal::of(trim($rate));

        if (strlen($baseCurrency) !== 3 || strlen($quoteCurrency) !== 3) {
            throw new DomainException('Exchange-rate currencies must be three-letter ISO codes.');
        }
        if ($baseCurrency === $quoteCurrency) {
            throw new DomainException('Exchange-rate currencies must be different.');
        }
        if ($decimal->isLessThanOrEqualTo(0)) {
            throw new DomainException('Exchange rate must be positive.');
        }
        if ($source === '') {
            throw new DomainException('Exchange-rate source is required.');
        }

        return ExchangeRate::query()->create([
            'property_id' => $propertyId,
            'base_currency' => $baseCurrency,
            'quote_currency' => $quoteCurrency,
            'rate' => $decimal->toScale(10, RoundingMode::HalfUp)->__toString(),
            'source' => $source,
            'effective_at' => $effectiveAt,
        ]);
    }

    public function applicable(
        string $baseCurrency,
        string $quoteCurrency,
        DateTimeInterface $effectiveAt,
        ?string $propertyId = null,
    ): ?ExchangeRate {
        $baseCurrency = strtoupper($baseCurrency);
        $quoteCurrency = strtoupper($quoteCurrency);

        if ($propertyId !== null) {
            $propertyRate = $this->query($baseCurrency, $quoteCurrency)
                ->where('property_id', $propertyId)
                ->where('effective_at', '<=', $effectiveAt)
                ->orderByDesc('effective_at')
                ->first();

            if ($propertyRate !== null) {
                return $propertyRate;
            }
        }

        return $this->query($baseCurrency, $quoteCurrency)
            ->whereNull('property_id')
            ->where('effective_at', '<=', $effectiveAt)
            ->orderByDesc('effective_at')
            ->first();
    }

    /**
     * Resolve a direct or inverse snapshot without hiding the direction used.
     * The returned rate is a ratio of source minor units to target minor units.
     *
     * @return array{snapshot: ExchangeRate|null, from_currency: string, to_currency: string, rate: string, source: string, effective_at: string|null, property_id: string|null, direction: string}|null
     */
    public function conversion(
        string $fromCurrency,
        string $toCurrency,
        DateTimeInterface $effectiveAt,
        ?string $propertyId = null,
    ): ?array {
        $fromCurrency = strtoupper($fromCurrency);
        $toCurrency = strtoupper($toCurrency);

        if ($fromCurrency === $toCurrency) {
            return [
                'snapshot' => null,
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'rate' => '1.0000000000',
                'source' => 'identity',
                'effective_at' => $effectiveAt instanceof CarbonInterface
                    ? $effectiveAt->toIso8601String()
                    : $effectiveAt->format(DateTimeInterface::ATOM),
                'property_id' => $propertyId,
                'direction' => 'identity',
            ];
        }

        $direct = $this->applicable($fromCurrency, $toCurrency, $effectiveAt, $propertyId);
        if ($direct !== null) {
            return $this->conversionData($direct, $fromCurrency, $toCurrency, 'direct', (string) $direct->rate);
        }

        $inverse = $this->applicable($toCurrency, $fromCurrency, $effectiveAt, $propertyId);
        if ($inverse === null) {
            return null;
        }

        $inverseRate = BigDecimal::one()
            ->dividedBy($inverse->rate, 20, RoundingMode::HalfUp)
            ->toScale(10, RoundingMode::HalfUp)
            ->__toString();

        return $this->conversionData($inverse, $fromCurrency, $toCurrency, 'inverse', $inverseRate);
    }

    public function convertMinor(int $amountMinor, ExchangeRate $snapshot): int
    {
        return $this->roundMinor(BigDecimal::of($amountMinor)->multipliedBy($snapshot->rate));
    }

    /** @param array{snapshot: ExchangeRate|null, direction: string, rate: string} $conversion */
    public function convertMinorForConversion(int $amountMinor, array $conversion): int
    {
        $amount = BigDecimal::of($amountMinor);

        if ($conversion['direction'] === 'inverse') {
            /** @var ExchangeRate $snapshot */
            $snapshot = $conversion['snapshot'];

            return $this->roundMinor($amount->dividedBy($snapshot->rate, 20, RoundingMode::HalfUp));
        }

        return $this->roundMinor($amount->multipliedBy($conversion['rate']));
    }

    private function query(string $baseCurrency, string $quoteCurrency)
    {
        return ExchangeRate::query()
            ->where('base_currency', $baseCurrency)
            ->where('quote_currency', $quoteCurrency);
    }

    /** @return array{snapshot: ExchangeRate, from_currency: string, to_currency: string, rate: string, source: string, effective_at: string|null, property_id: string|null, direction: string} */
    private function conversionData(
        ExchangeRate $snapshot,
        string $fromCurrency,
        string $toCurrency,
        string $direction,
        string $rate,
    ): array {
        return [
            'snapshot' => $snapshot,
            'from_currency' => $fromCurrency,
            'to_currency' => $toCurrency,
            'rate' => $rate,
            'source' => $snapshot->source,
            'effective_at' => $snapshot->effective_at->toIso8601String(),
            'property_id' => $snapshot->property_id,
            'direction' => $direction,
        ];
    }

    private function roundMinor(BigDecimal $amount): int
    {
        return $amount->toScale(0, RoundingMode::HalfUp)->toInt();
    }
}
