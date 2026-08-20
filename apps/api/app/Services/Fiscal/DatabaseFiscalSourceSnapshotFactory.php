<?php

namespace App\Services\Fiscal;

use App\Contracts\Fiscal\FiscalSourceSnapshotFactory;
use App\Enums\FolioLineType;
use App\Models\FiscalSourceSnapshot;
use App\Models\FolioLine;
use App\Models\Reservation;
use App\Services\Documents\CanonicalJson;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class DatabaseFiscalSourceSnapshotFactory implements FiscalSourceSnapshotFactory
{
    public function capture(Reservation $reservation): FiscalSourceSnapshot
    {
        return DB::transaction(function () use ($reservation): FiscalSourceSnapshot {
            $locked = Reservation::query()->with(['property', 'primaryGuest'])->lockForUpdate()->findOrFail($reservation->id);
            /** @var Collection<int, FolioLine> $lines */
            $lines = $locked->folioLines()->orderBy('posted_at')->orderBy('id')->lockForUpdate()->get();
            $lineFacts = $lines->map(fn (FolioLine $line): array => [
                'id' => $line->id, 'type' => $line->type->value, 'description' => $line->description,
                'quantity' => $line->quantity, 'unit_amount_minor' => $line->unit_amount_minor,
                'net_amount_minor' => $line->net_amount_minor, 'tax_amount_minor' => $line->tax_amount_minor,
                'gross_amount_minor' => $line->gross_amount_minor, 'payment_id' => $line->payment_id,
                'reverses_folio_line_id' => $line->reverses_folio_line_id, 'metadata' => $line->metadata,
                'posted_at' => $line->posted_at,
            ])->all();
            $canonicalJson = app(CanonicalJson::class);
            $sourceRevision = $canonicalJson->checksum([
                'reservation_revision' => $locked->revision,
                'folio_status' => $locked->folio_status->value,
                'folio_closed_at' => $locked->folio_closed_at,
                'folio_lines' => $lineFacts,
            ]);
            $revenue = $lines->filter(fn (FolioLine $line): bool => in_array($line->type, [FolioLineType::Charge, FolioLineType::Adjustment], true));
            $payments = $lines->where('type', FolioLineType::Payment);
            $refunds = $lines->where('type', FolioLineType::Refund);
            $gross = (int) $revenue->sum('gross_amount_minor');
            $ledgerBalance = (int) $lines->sum('gross_amount_minor');
            $snapshot = [
                'document_boundary' => 'non_fiscal_operational_source',
                'reservation_id' => $locked->id,
                'reservation_revision' => $locked->revision,
                'source_revision' => $sourceRevision,
                'property' => ['id' => $locked->property_id, 'name' => $locked->property->name, 'timezone' => $locked->property->timezone],
                'guest' => ['id' => $locked->primary_guest_id, 'name' => trim($locked->primaryGuest->first_name.' '.($locked->primaryGuest->last_name ?? ''))],
                'currency' => $locked->currency,
                'net_minor' => (int) $revenue->sum('net_amount_minor'),
                'tax_minor' => (int) $revenue->sum('tax_amount_minor'),
                'gross_minor' => $gross,
                'price_snapshot' => $locked->price_snapshot,
                'folio_lines' => $lineFacts,
                'settlement' => [
                    'payments_minor' => -(int) $payments->sum('gross_amount_minor'),
                    'refunds_minor' => (int) $refunds->sum('gross_amount_minor'),
                    'ledger_balance_minor' => $ledgerBalance,
                ],
                'reconciliation' => [
                    'reservation_total_minor' => $locked->total_minor,
                    'folio_revenue_gross_minor' => $gross,
                    'revenue_delta_minor' => $gross - $locked->total_minor,
                    'identity' => 'sum(folio.gross_amount_minor)=ledger_balance_minor',
                ],
            ];
            $canonical = $canonicalJson->encode($snapshot);
            $snapshot = json_decode($canonical, true, flags: JSON_THROW_ON_ERROR);

            return FiscalSourceSnapshot::query()->firstOrCreate(
                ['reservation_id' => $locked->id, 'source_revision' => $sourceRevision, 'source_type' => 'operational_folio'],
                [
                    'reservation_revision' => $locked->revision,
                    'property_id' => $locked->property_id, 'currency' => $locked->currency,
                    'net_minor' => (int) $revenue->sum('net_amount_minor'), 'tax_minor' => (int) $revenue->sum('tax_amount_minor'),
                    'gross_minor' => $gross, 'source_snapshot' => $snapshot,
                    'checksum' => hash('sha256', $canonical), 'captured_at' => now(),
                ],
            );
        }, 3);
    }
}
