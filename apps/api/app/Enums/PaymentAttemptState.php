<?php

namespace App\Enums;

enum PaymentAttemptState: string
{
    case Creating = 'creating';
    case CheckoutReady = 'checkout_ready';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Mismatched = 'mismatched';
    case Failed = 'failed';

    public function reusable(): bool
    {
        return in_array($this, [self::Creating, self::CheckoutReady, self::Pending], true);
    }

    public function terminal(): bool
    {
        return ! $this->reusable();
    }
}
