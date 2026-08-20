<?php

namespace App\Services;

use App\Models\BookingQuote;
use App\Models\CommercialPromotionUsage;
use App\Models\VoucherRedemption;

final class QuoteExplanationService
{
    /** @return array<string, mixed> */
    public function project(BookingQuote $quote): array
    {
        $quote->loadMissing('lines');
        $quoteHistory = $quote->reservation_id === null
            ? collect([$quote])
            : BookingQuote::query()->with('lines')->where('reservation_id', $quote->reservation_id)
                ->orderBy('created_at')->orderBy('id')->get();
        $usageScope = fn ($query) => $quote->reservation_id === null
            ? $query->where('booking_quote_id', $quote->id)
            : $query->where('reservation_id', $quote->reservation_id);
        $promotionUsages = $usageScope(CommercialPromotionUsage::query())
            ->with(['promotion', 'voucher', 'events' => fn ($query) => $query->orderBy('occurred_at')->orderBy('id')])
            ->orderBy('reserved_at')->orderBy('id')->get();
        $voucherRedemptions = $usageScope(VoucherRedemption::query())
            ->with(['voucher.promotion', 'events' => fn ($query) => $query->orderBy('occurred_at')->orderBy('id')])
            ->orderBy('reserved_at')->orderBy('id')->get();

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
            'lines' => $this->lineFacts($quote),
            'deposit_policy' => $quote->deposit_policy_snapshot,
            'cancellation_policy' => $quote->cancellation_policy_snapshot,
            'quote_history' => $quoteHistory->map(fn (BookingQuote $historicalQuote): array => [
                'quote_id' => $historicalQuote->id,
                'checksum' => $historicalQuote->checksum,
                'status' => $historicalQuote->status->value,
                'currency' => $historicalQuote->currency,
                'subtotal_minor' => $historicalQuote->subtotal_minor,
                'discount_minor' => $historicalQuote->discount_minor,
                'tax_minor' => $historicalQuote->tax_minor,
                'total_minor' => $historicalQuote->total_minor,
                'lines' => $this->lineFacts($historicalQuote),
                'deposit_policy' => $historicalQuote->deposit_policy_snapshot,
                'cancellation_policy' => $historicalQuote->cancellation_policy_snapshot,
            ])->all(),
            'promotion_usage_history' => $promotionUsages->map(fn (CommercialPromotionUsage $usage): array => [
                'usage_id' => $usage->id,
                'booking_quote_id' => $usage->booking_quote_id,
                'promotion_id' => $usage->commercial_promotion_id,
                'promotion_name' => $usage->promotion->name,
                'promotion_version' => $usage->promotion->version,
                'voucher_id' => $usage->voucher_id,
                'voucher_label' => $usage->voucher?->public_label,
                'state' => $usage->state,
                'currency' => $usage->currency,
                'discount_minor' => $usage->discount_minor,
                'superseded_by_id' => $usage->superseded_by_id,
                'events' => $usage->events->map(fn ($event): array => [
                    'type' => $event->type,
                    'occurred_at' => $event->occurred_at->toIso8601String(),
                    'facts' => $event->facts,
                ])->all(),
            ])->all(),
            'voucher_redemption_history' => $voucherRedemptions->map(fn (VoucherRedemption $redemption): array => [
                'redemption_id' => $redemption->id,
                'booking_quote_id' => $redemption->booking_quote_id,
                'voucher_id' => $redemption->voucher_id,
                'voucher_label' => $redemption->voucher->public_label,
                'promotion_id' => $redemption->voucher->commercial_promotion_id,
                'promotion_version' => $redemption->voucher->promotion->version,
                'state' => $redemption->state,
                'currency' => $redemption->currency,
                'discount_minor' => $redemption->discount_minor,
                'events' => $redemption->events->map(fn ($event): array => [
                    'type' => $event->type,
                    'policy_reason' => $event->policy_reason,
                    'occurred_at' => $event->occurred_at->toIso8601String(),
                    'facts' => $event->facts,
                ])->all(),
            ])->all(),
            'historical_projection' => true,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function lineFacts(BookingQuote $quote): array
    {
        $quote->loadMissing('lines');

        return $quote->lines->sortBy('calculation_order')->values()->map(fn ($line): array => [
            'type' => $line->type,
            'description' => $line->description,
            'service_on' => $line->service_on?->toDateString(),
            'basis' => $line->basis,
            'calculation_order' => $line->calculation_order,
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
        ])->all();
    }
}
