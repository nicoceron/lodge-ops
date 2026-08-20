<?php

namespace App\Contracts\Fiscal;

use App\Models\FiscalSourceSnapshot;
use App\Models\Reservation;

interface FiscalSourceSnapshotFactory
{
    public function capture(Reservation $reservation): FiscalSourceSnapshot;
}
