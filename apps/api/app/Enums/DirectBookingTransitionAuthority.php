<?php

namespace App\Enums;

enum DirectBookingTransitionAuthority: string
{
    case Pricing = 'pricing_service';
    case Inventory = 'inventory_service';
    case PaymentOrchestrator = 'payment_orchestrator';
    case ProviderLookup = 'provider_authoritative_lookup';
    case GuestEvidence = 'guest_evidence_service';
    case EvidenceScanner = 'evidence_scanner';
    case Finance = 'finance_review';
    case Reservation = 'reservation_service';
    case Scheduler = 'scheduler';
    case Recovery = 'recovery_service';
    case Refund = 'refund_service';
    case Cancellation = 'cancellation_service';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
