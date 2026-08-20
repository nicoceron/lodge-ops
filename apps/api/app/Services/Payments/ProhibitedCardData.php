<?php

namespace App\Services\Payments;

final class ProhibitedCardData
{
    public function __construct(private readonly SensitivePaymentDataGuard $guard) {}

    /** @param array<string, string|null> $fields @param list<string> $luhnFalsePositiveFields */
    public function assertSafe(array $fields, array $luhnFalsePositiveFields = []): void
    {
        $this->guard->assertSafe($fields, '', $luhnFalsePositiveFields);
    }

    /**
     * @template T
     *
     * @param  list<string>  $fields
     * @param  callable(): T  $callback
     * @return T
     */
    public function withLuhnFalsePositiveResolution(array $fields, string $justification, callable $callback): mixed
    {
        return $this->guard->withLuhnFalsePositiveResolution($fields, $justification, $callback);
    }

    /** @param list<string> $fields */
    public function validateLuhnFalsePositiveResolution(array $fields, string $justification): void
    {
        $this->guard->validateLuhnFalsePositiveResolution($fields, $justification);
    }
}
