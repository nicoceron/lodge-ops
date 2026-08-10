<?php

namespace App\Exceptions;

use App\Enums\ReservationStatus;
use DomainException;

class InvalidStatusTransitionException extends DomainException
{
    public function __construct(ReservationStatus $from, ReservationStatus $to)
    {
        parent::__construct("Reservation cannot transition from {$from->value} to {$to->value}.");
    }
}
