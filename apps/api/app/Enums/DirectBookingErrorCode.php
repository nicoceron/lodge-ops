<?php

namespace App\Enums;

enum DirectBookingErrorCode: string
{
    case Validation = 'validation_error';
    case Unavailable = 'unavailable';
    case QuoteStale = 'quote_stale';
    case HoldExpired = 'hold_expired';
    case Conflict = 'conflict';
    case IdempotencyConflict = 'idempotency_conflict';
    case RateLimited = 'rate_limited';
    case BotRejected = 'bot_rejected';
    case PaymentPending = 'payment_pending';
    case PaymentFailed = 'payment_failed';
    case PaidNeedsReview = 'paid_needs_review';
    case NotFound = 'not_found';
    case BookingUnavailable = 'booking_unavailable';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
