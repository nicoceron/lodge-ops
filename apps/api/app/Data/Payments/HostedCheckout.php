<?php

namespace App\Data\Payments;

use Carbon\CarbonImmutable;

final readonly class HostedCheckout
{
    public function __construct(
        public string $preferenceId,
        public string $url,
        public ?CarbonImmutable $expiresAt = null,
    ) {}
}
