<?php

namespace App\Services;

use App\Models\BookingQuote;

final class QuoteExplanationService
{
    /** @return array<string, mixed> */
    public function project(BookingQuote $quote): array
    {
        $quote->loadMissing('lines');

        return [
            'quote_id' => $quote->id,
            'checksum' => $quote->checksum,
            'status' => $quote->status->value,
            'currency' => $quote->currency,
            'subtotal_minor' => $quote->subtotal_minor,
            'discount_minor' => $quote->discount_minor,
            'tax_minor' => $quote->tax_minor,
            'total_minor' => $quote->total_minor,
            'calculation_snapshot' => $quote->calculation_snapshot,
            'lines' => $quote->lines->sortBy('calculation_order')->values()->map(fn ($line): array => [
                'type' => $line->type,
                'description' => $line->description,
                'basis' => $line->basis,
                'quantity_thousandths' => $line->quantity_thousandths,
                'unit_amount_minor' => $line->unit_amount_minor,
                'net_amount_minor' => $line->net_amount_minor,
                'tax_amount_minor' => $line->tax_amount_minor,
                'gross_amount_minor' => $line->gross_amount_minor,
                'pre_total_minor' => $line->pre_total_minor,
                'post_total_minor' => $line->post_total_minor,
                'rounding_mode' => $line->rounding_mode,
                'explanation' => $line->explanation,
                'rule_facts' => $line->metadata,
            ])->all(),
            'deposit_policy' => $quote->deposit_policy_snapshot,
            'cancellation_policy' => $quote->cancellation_policy_snapshot,
            'historical_projection' => true,
        ];
    }
}
