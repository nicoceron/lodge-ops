<?php

namespace App\Enums;

enum DirectBookingPublicationKind: string
{
    case Property = 'property';
    case Category = 'category';
    case Program = 'program';
    case Terms = 'terms';
    case Privacy = 'privacy';
    case Cancellation = 'cancellation';
    case NoShow = 'no_show';
    case MarketingConsent = 'marketing_consent';
    case BankTransferInstructions = 'bank_transfer_instructions';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isRequiredPolicy(): bool
    {
        return in_array($this, [self::Terms, self::Privacy, self::Cancellation, self::NoShow], true);
    }
}
