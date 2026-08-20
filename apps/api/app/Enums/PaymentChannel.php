<?php

namespace App\Enums;

enum PaymentChannel: string
{
    case BankTransfer = 'bank_transfer';
    case Cash = 'cash';
    case ExternalTerminal = 'external_terminal';
    case ManualOther = 'manual_other';
    case OnlineCheckout = 'online_checkout';
    case IntegratedTerminal = 'integrated_terminal';
    case Qr = 'qr';

    public function legacyMethod(): string
    {
        return match ($this) {
            self::BankTransfer => 'bank_transfer',
            self::Cash => 'cash',
            self::ExternalTerminal, self::IntegratedTerminal => 'card',
            self::ManualOther => 'other',
            self::OnlineCheckout, self::Qr => 'mercado_pago_checkout_pro',
        };
    }
}
