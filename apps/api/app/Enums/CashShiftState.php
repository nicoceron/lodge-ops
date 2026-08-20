<?php

namespace App\Enums;

enum CashShiftState: string
{
    case Open = 'open';
    case Closed = 'closed';
    case VarianceReview = 'variance_review';
}
