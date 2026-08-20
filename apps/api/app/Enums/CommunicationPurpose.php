<?php

namespace App\Enums;

enum CommunicationPurpose: string
{
    case Transactional = 'transactional';
    case ReservationConfirmation = 'reservation_confirmation';
    case Proposal = 'proposal';
    case PaymentRequest = 'payment_request';
    case PaymentReceipt = 'payment_receipt';
    case RefundReceipt = 'refund_receipt';
    case PreArrival = 'pre_arrival';
    case CheckoutFolio = 'checkout_folio';
    case Survey = 'survey';
    case Operational = 'operational';
    case InternalGuide = 'internal_guide';
    case InternalKitchen = 'internal_kitchen';
    case InternalHost = 'internal_host';
    case InternalFinance = 'internal_finance';
    case Marketing = 'marketing';
    case Test = 'test';
}
