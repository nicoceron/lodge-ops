<?php

namespace App\Services\Payments;

final class ProhibitedCardData
{
    public function __construct(private readonly SensitivePaymentDataGuard $guard) {}

    /** @param array<string, string|null> $fields */
    public function assertSafe(array $fields): void
    {
        $this->guard->assertSafe($fields, '');
    }
}
