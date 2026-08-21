<?php

namespace App\Enums;

enum PaymentAttemptState: string
{
    case Creating = 'creating';
    case CheckoutReady = 'checkout_ready';
    case Pending = 'pending';
    case Queued = 'queued';
    case AtTerminal = 'at_terminal';
    case ActionRequired = 'action_required';
    case Processing = 'processing';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Mismatched = 'mismatched';
    case Failed = 'failed';

    public function reusable(): bool
    {
        return in_array($this, [self::Creating, self::CheckoutReady, self::Pending, self::Queued, self::AtTerminal, self::ActionRequired, self::Processing], true);
    }

    public function terminal(): bool
    {
        return ! $this->reusable();
    }
}
