<?php

namespace App\Enums;

enum SettlementReconciliationState: string
{
    case Matched = 'matched';
    case Variance = 'variance';
    case Unmatched = 'unmatched';
    case IgnoredWithReason = 'ignored_with_reason';
}
