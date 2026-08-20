<?php

namespace App\Services\Payments;

use App\Data\Payments\ProviderPayment;
use App\Models\PaymentAttempt;
use App\Models\SettlementEntry;
use App\Models\SettlementEntryRevision;
use Illuminate\Support\Facades\DB;

final class RecordSettlementRevision
{
    public function handle(PaymentAttempt $attempt, ProviderPayment $payment): SettlementEntry
    {
        return DB::transaction(function () use ($attempt, $payment): SettlementEntry {
            $facts = [
                'gross_minor' => (int) ($payment->settlement['gross_minor'] ?? $payment->amountMinor),
                'fee_minor' => (int) ($payment->settlement['fee_minor'] ?? 0),
                'tax_minor' => $payment->settlement['tax_minor'] ?? null,
                'withholding_minor' => $payment->settlement['withholding_minor'] ?? null,
                'financing_minor' => $payment->settlement['financing_minor'] ?? null,
                'refunded_minor' => $payment->settlement['refunded_minor'] ?? null,
                'chargeback_minor' => $payment->settlement['chargeback_minor'] ?? null,
                'net_minor' => (int) ($payment->settlement['net_minor'] ?? $payment->amountMinor),
                'currency' => $payment->currency,
                'settlement_currency' => $payment->settlement['settlement_currency'] ?? null,
                'settlement_identity' => $payment->settlement['settlement_identity'] ?? null,
                'settlement_date' => $this->date($payment->settlement['settlement_date'] ?? null),
                'settlement_status' => $payment->settlement['settlement_status'] ?? null,
                'payout_identity' => $payment->settlement['payout_identity'] ?? null,
                'payout_date' => $this->date($payment->settlement['payout_date'] ?? null),
                'payout_status' => $payment->settlement['payout_status'] ?? null,
            ];
            $checksum = hash('sha256', json_encode(['facts' => $facts, 'provider_facts' => $payment->settlement], JSON_THROW_ON_ERROR));
            $entry = SettlementEntry::query()->firstOrCreate([
                'provider' => $attempt->provider,
                'environment' => $attempt->environment,
                'provider_account' => $attempt->provider_account,
                'source_type' => 'payment',
                'source_id' => $payment->id,
            ], [
                'integration_connection_id' => $attempt->integration_connection_id,
                'property_id' => $attempt->property_id,
                ...$facts,
                'source_checksum' => $checksum,
                'reconciliation_state' => 'unmatched',
            ]);
            $locked = SettlementEntry::query()->lockForUpdate()->findOrFail($entry->id);
            $existing = $locked->revisions()->where('facts_checksum', $checksum)->first();
            if ($existing === null) {
                $revision = ((int) $locked->revisions()->max('revision')) + 1;
                SettlementEntryRevision::query()->create([
                    'settlement_entry_id' => $locked->id,
                    'revision' => $revision,
                    ...$facts,
                    'facts_checksum' => $checksum,
                    'provider_facts' => $payment->settlement,
                    'recorded_at' => now(),
                ]);
            }
            $expectedNet = ($payment->settlement['net_is_authoritative'] ?? false) === true
                ? $facts['net_minor']
                : $facts['gross_minor']
                    - $facts['fee_minor']
                    - (int) ($facts['tax_minor'] ?? 0)
                    - (int) ($facts['withholding_minor'] ?? 0)
                    - (int) ($facts['financing_minor'] ?? 0)
                    - (int) ($facts['refunded_minor'] ?? 0)
                    - (int) ($facts['chargeback_minor'] ?? 0);
            $locked->update([
                'property_id' => $attempt->property_id,
                ...$facts,
                'source_checksum' => $checksum,
                'reconciliation_state' => $expectedNet === $facts['net_minor'] ? 'matched' : 'variance',
                'resolution_reason' => $expectedNet === $facts['net_minor'] ? null : $locked->resolution_reason,
                'resolved_by' => $expectedNet === $facts['net_minor'] ? null : $locked->resolved_by,
                'resolved_at' => $expectedNet === $facts['net_minor'] ? null : $locked->resolved_at,
            ]);

            return $locked->fresh('revisions');
        }, 3);
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }
}
