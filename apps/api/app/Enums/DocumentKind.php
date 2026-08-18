<?php

namespace App\Enums;

enum DocumentKind: string
{
    case ReservationConfirmation = 'reservation_confirmation';
    case Itinerary = 'itinerary';
    case FolioStatement = 'folio_statement';
    case PaymentReceipt = 'payment_receipt';
    case RefundReceipt = 'refund_receipt';
    case WaiverCopy = 'waiver_copy';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
