<?php

namespace App\Data\DirectBooking;

final readonly class BotVerificationResult
{
    /** @param list<string> $safeErrorCodes */
    public function __construct(
        public bool $valid,
        public ?string $challengeHostname,
        public ?string $action,
        public array $safeErrorCodes = [],
    ) {}
}
