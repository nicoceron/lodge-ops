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

    public function httpStatus(): int
    {
        return match ($this) {
            self::Validation => 422,
            self::BotRejected => 403,
            self::NotFound => 404,
            self::HoldExpired => 410,
            self::RateLimited => 429,
            self::BookingUnavailable => 503,
            default => 409,
        };
    }

    public function retryable(): bool
    {
        return match ($this) {
            self::Unavailable,
            self::QuoteStale,
            self::HoldExpired,
            self::Conflict,
            self::RateLimited,
            self::BotRejected,
            self::PaymentPending,
            self::PaymentFailed,
            self::BookingUnavailable => true,
            default => false,
        };
    }

    public function publicMessage(): string
    {
        return match ($this) {
            self::Validation => 'The request did not satisfy the booking contract.',
            self::Unavailable => 'The requested item is not available for these dates.',
            self::QuoteStale => 'The authoritative quote has expired and must be refreshed.',
            self::HoldExpired => 'The inventory hold has expired.',
            self::Conflict => 'The booking state changed; refresh before retrying.',
            self::IdempotencyConflict => 'This idempotency key was already used for different request facts.',
            self::RateLimited => 'Too many booking requests were received. Retry after the advertised delay.',
            self::BotRejected => 'Bot verification could not be completed.',
            self::PaymentPending => 'Payment is still pending authoritative provider confirmation.',
            self::PaymentFailed => 'The authoritative payment attempt failed and may be retried.',
            self::PaidNeedsReview => 'Payment was received but the reservation requires Finance review.',
            self::NotFound => 'The requested booking resource was not found.',
            self::BookingUnavailable => 'Direct booking is temporarily unavailable for this property.',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
