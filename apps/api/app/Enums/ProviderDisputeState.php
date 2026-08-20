<?php

namespace App\Enums;

enum ProviderDisputeState: string
{
    case Open = 'open';
    case UnderReview = 'under_review';
    case Won = 'won';
    case Lost = 'lost';
    case Unknown = 'unknown';
}
