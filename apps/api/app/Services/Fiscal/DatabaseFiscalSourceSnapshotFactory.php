<?php

namespace App\Services\Fiscal;

use App\Contracts\Fiscal\FiscalSourceSnapshotFactory;
use App\Models\FiscalSourceSnapshot;
use App\Models\Reservation;
use App\Services\Documents\CanonicalJson;
use Illuminate\Support\Facades\DB;

final class DatabaseFiscalSourceSnapshotFactory implements FiscalSourceSnapshotFactory
{
    public function capture(Reservation $reservation): FiscalSourceSnapshot
    {
        return DB::transaction(function () use ($reservation): FiscalSourceSnapshot {
            $locked = Reservation::query()->with(['property', 'primaryGuest', 'folioLines'])->lockForUpdate()->findOrFail($reservation->id);
            $snapshot = [
                'document_boundary' => 'non_fiscal_operational_source',
                'reservation_id' => $locked->id,
                'reservation_revision' => $locked->revision,
                'property' => ['id' => $locked->property_id, 'name' => $locked->property->name, 'timezone' => $locked->property->timezone],
                'guest' => ['id' => $locked->primary_guest_id, 'name' => trim($locked->primaryGuest->first_name.' '.($locked->primaryGuest->last_name ?? ''))],
                'currency' => $locked->currency,
                'net_minor' => $locked->subtotal_minor,
                'tax_minor' => $locked->tax_minor,
                'gross_minor' => $locked->total_minor,
                'price_snapshot' => $locked->price_snapshot,
                'folio_lines' => $locked->folioLines->map->only(['id', 'type', 'description', 'quantity', 'unit_amount_minor', 'tax_amount_minor', 'gross_amount_minor'])->all(),
            ];
            $canonical = app(CanonicalJson::class)->encode($snapshot);

            return FiscalSourceSnapshot::query()->firstOrCreate(
                ['reservation_id' => $locked->id, 'reservation_revision' => $locked->revision, 'source_type' => 'operational_folio'],
                [
                    'property_id' => $locked->property_id, 'currency' => $locked->currency,
                    'net_minor' => $locked->subtotal_minor, 'tax_minor' => $locked->tax_minor,
                    'gross_minor' => $locked->total_minor, 'source_snapshot' => $snapshot,
                    'checksum' => hash('sha256', $canonical), 'captured_at' => now(),
                ],
            );
        }, 3);
    }
}
