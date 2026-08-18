<?php

namespace App\Enums;

enum ReportExportKind: string
{
    case Arrivals = 'arrivals';
    case Departures = 'departures';
    case Occupancy = 'occupancy';
    case Revenue = 'revenue';
    case PaymentsDepositsRefunds = 'payments_deposits_refunds';
    case CostsMarginCommissions = 'costs_margin_commissions';
    case Dietary = 'dietary';
    case TasksHousekeeping = 'tasks_housekeeping';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
