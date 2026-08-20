<?php

namespace App\Http\Controllers;

use App\Models\CashShift;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CashShiftReportController extends Controller
{
    public function __invoke(string $tenant, CashShift $cashShift): StreamedResponse
    {
        $this->authorize('view', $cashShift);
        $cashShift->load(['cashier', 'movements']);

        return response()->streamDownload(function () use ($cashShift): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                return;
            }
            fputcsv($output, ['Cash shift', $cashShift->id]);
            fputcsv($output, ['Business date', $cashShift->business_date->toDateString()]);
            fputcsv($output, ['Cashier', $cashShift->cashier?->name]);
            fputcsv($output, ['Currency', $cashShift->currency]);
            fputcsv($output, ['State', $cashShift->state->value]);
            fputcsv($output, ['Expected minor', $cashShift->state->value === 'open' ? $cashShift->currentExpectedMinor() : $cashShift->expected_cash_minor]);
            fputcsv($output, ['Counted minor', $cashShift->counted_cash_minor]);
            fputcsv($output, ['Variance minor', $cashShift->variance_minor]);
            fputcsv($output, []);
            fputcsv($output, ['Movement ID', 'Occurred UTC', 'Type', 'Amount minor', 'Currency', 'Reason', 'Reverses movement']);
            foreach ($cashShift->movements as $movement) {
                fputcsv($output, [$movement->id, $movement->occurred_at->utc()->toIso8601String(), $movement->type->value, $movement->amount_minor, $movement->currency, $movement->reason, $movement->reverses_movement_id]);
            }
            fclose($output);
        }, 'cash-shift-'.$cashShift->id.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
