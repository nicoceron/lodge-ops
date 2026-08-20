<?php

namespace App\Data\DirectBooking;

use App\Models\DirectBookingOrder;
use App\Models\DirectBookingOrderEvent;

final readonly class DirectBookingTransitionResult
{
    public function __construct(
        public DirectBookingOrder $order,
        public DirectBookingOrderEvent $event,
        public bool $replayed,
    ) {}
}
