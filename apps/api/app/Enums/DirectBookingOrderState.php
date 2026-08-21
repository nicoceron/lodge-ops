<?php

namespace App\Enums;

enum DirectBookingOrderState: string
{
    case Started = 'started';
    case Quoted = 'quoted';
    case Held = 'held';
    case PaymentPending = 'payment_pending';
    case AwaitingManualPayment = 'awaiting_manual_payment';
    case EvidencePending = 'evidence_pending';
    case FinanceReview = 'finance_review';
    case PaidPendingConfirmation = 'paid_pending_confirmation';
    case Confirmed = 'confirmed';
    case Expired = 'expired';
    case PaymentFailed = 'payment_failed';
    case PaidNeedsReview = 'paid_needs_review';
    case EvidenceRejected = 'evidence_rejected';
    case Canceled = 'canceled';
    case Refunded = 'refunded';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Canceled, self::Refunded], true);
    }
}
