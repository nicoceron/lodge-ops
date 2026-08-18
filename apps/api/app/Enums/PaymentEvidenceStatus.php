<?php

namespace App\Enums;

enum PaymentEvidenceStatus: string
{
    case Pending = 'review_pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case MoreInformationRequired = 'more_information_required';

    public function isFinal(): bool
    {
        return in_array($this, [self::Approved, self::Rejected], true);
    }
}
