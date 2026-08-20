<?php

namespace App\Enums;

enum ProviderDisputeImpactState: string
{
    case None = 'none';
    case PendingFinance = 'pending_finance';
    case Applied = 'applied';
    case Reversed = 'reversed';
}
