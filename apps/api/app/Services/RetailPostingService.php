<?php

namespace App\Services;

use App\Enums\FolioLineType;
use App\Models\CatalogItem;
use App\Models\FolioLine;
use App\Models\Reservation;
use App\Models\RetailSale;
use App\Models\StockLocation;
use App\Models\StockMovement;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Support\Facades\DB;

class RetailPostingService
{
    /**
     * @param  list<array{catalog_item_id:string, quantity_milli:int}>  $lines
     */
    public function post(
        StockLocation $location,
        string $reference,
        array $lines,
        ?Reservation $reservation = null,
        int $taxMinor = 0,
    ): RetailSale {
        if ($lines === [] || $taxMinor < 0) {
            throw new DomainException('A sale needs at least one line and a non-negative tax.');
        }

        return DB::transaction(function () use ($location, $reference, $lines, $reservation, $taxMinor): RetailSale {
            $existing = RetailSale::query()->where('reference', $reference)->first();
            if ($existing !== null) {
                return $existing->load('lines');
            }

            $quantities = [];
            foreach ($lines as $line) {
                $quantity = (int) ($line['quantity_milli'] ?? 0);
                if ($quantity <= 0) {
                    throw new DomainException('Sale quantities must be positive thousandths.');
                }
                $itemId = (string) ($line['catalog_item_id'] ?? '');
                $quantities[$itemId] = ($quantities[$itemId] ?? 0) + $quantity;
            }

            /** @var array<string, CatalogItem> $items */
            $items = CatalogItem::query()->whereIn('id', array_keys($quantities))->lockForUpdate()->get()->keyBy('id')->all();
            if (count($items) !== count($quantities)) {
                throw new DomainException('A catalog item is unavailable.');
            }
            $existing = RetailSale::query()->where('reference', $reference)->first();
            if ($existing !== null) {
                return $existing->load('lines');
            }

            $currency = null;
            $subtotal = 0;
            foreach ($quantities as $itemId => $quantityMilli) {
                $item = $items[$itemId];
                if (! $item->is_active) {
                    throw new DomainException('Inactive catalog items cannot be sold.');
                }
                $currency ??= $item->currency;
                if ($currency !== $item->currency || ($reservation !== null && $reservation->currency !== $item->currency)) {
                    throw new DomainException('Mixed-currency sales are forbidden.');
                }
                if ($item->track_stock && $this->balanceMilli($item, $location) < $quantityMilli) {
                    throw new DomainException("Insufficient stock for {$item->name}.");
                }
                $subtotal += $this->multiplyMinor($item->price_minor, $quantityMilli);
            }

            $sale = RetailSale::query()->create([
                'reservation_id' => $reservation?->id,
                'stock_location_id' => $location->id,
                'reference' => $reference,
                'status' => 'posted',
                'currency' => $currency,
                'subtotal_minor' => $subtotal,
                'tax_minor' => $taxMinor,
                'total_minor' => $subtotal + $taxMinor,
                'posted_at' => now(),
            ]);

            foreach ($quantities as $itemId => $quantityMilli) {
                $item = $items[$itemId];
                $amount = $this->multiplyMinor($item->price_minor, $quantityMilli);
                $folioLine = $reservation === null ? null : FolioLine::query()->create([
                    'reservation_id' => $reservation->id,
                    'type' => FolioLineType::Charge,
                    'description' => $item->name,
                    'quantity' => $this->decimalQuantity($quantityMilli),
                    'unit_amount_minor' => $item->price_minor,
                    'amount_minor' => $amount,
                    'currency' => $item->currency,
                    'posted_at' => now(),
                    'metadata' => ['source' => 'retail_sale', 'sale_reference' => $reference],
                ]);

                $sale->lines()->create([
                    'catalog_item_id' => $item->id,
                    'folio_line_id' => $folioLine?->id,
                    'quantity' => $this->decimalQuantity($quantityMilli),
                    'unit_amount_minor' => $item->price_minor,
                    'amount_minor' => $amount,
                ]);

                if ($item->track_stock) {
                    StockMovement::query()->create([
                        'catalog_item_id' => $item->id,
                        'stock_location_id' => $location->id,
                        'retail_sale_id' => $sale->id,
                        'type' => 'sale',
                        'quantity' => $this->decimalQuantity(-$quantityMilli),
                        'unit_cost_minor' => $item->cost_minor,
                        'reference' => "{$reference}:{$item->id}",
                        'occurred_at' => now(),
                    ]);
                }
            }

            return $sale->load('lines');
        }, 3);
    }

    private function balanceMilli(CatalogItem $item, StockLocation $location): int
    {
        $quantity = StockMovement::query()
            ->where('catalog_item_id', $item->id)
            ->where('stock_location_id', $location->id)
            ->sum('quantity');

        return BigDecimal::of((string) $quantity)
            ->multipliedBy(1000)
            ->toScale(0, RoundingMode::Unnecessary)
            ->toInt();
    }

    private function multiplyMinor(int $unitMinor, int $quantityMilli): int
    {
        return intdiv(($unitMinor * $quantityMilli) + 500, 1000);
    }

    private function decimalQuantity(int $quantityMilli): string
    {
        $sign = $quantityMilli < 0 ? '-' : '';
        $absolute = abs($quantityMilli);

        return sprintf('%s%d.%03d', $sign, intdiv($absolute, 1000), $absolute % 1000);
    }
}
