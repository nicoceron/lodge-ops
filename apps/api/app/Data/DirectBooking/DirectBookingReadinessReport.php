<?php

namespace App\Data\DirectBooking;

final readonly class DirectBookingReadinessReport
{
    /** @param list<string> $blockingReasons */
    public function __construct(
        public bool $ready,
        public array $blockingReasons,
    ) {}
}
