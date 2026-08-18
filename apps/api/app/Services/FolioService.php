<?php

namespace App\Services;

use App\Enums\FolioLineType;
use App\Enums\FolioStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\CommercialWorkflowException as DomainException;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationChange;
use Illuminate\Support\Facades\DB;

final class FolioService
{
    public function __construct(private readonly MoneyCalculator $money) {}

    /** @param array<string, mixed> $metadata */
    public function append(
        Reservation $reservation,
        FolioLineType $type,
        string $description,
        int $quantityThousandths,
        int $unitAmountMinor,
        ?int $actorId,
        array $metadata = [],
        int $taxAmountMinor = 0,
        bool $includedInBookedTotal = false,
    ): FolioLine {
        $this->assertOpen($reservation);
        if (! in_array($type, [FolioLineType::Charge, FolioLineType::Adjustment], true)) {
            throw new DomainException('Manual folio entries must be charges or adjustments.');
        }
        unset($metadata['included_in_booked_total']);
        if ($includedInBookedTotal) {
            $metadata['included_in_booked_total'] = true;
        }

        return $this->createLine(
            reservation: $reservation,
            type: $type,
            description: $description,
            quantityThousandths: $quantityThousandths,
            unitAmountMinor: $unitAmountMinor,
            actorId: $actorId,
            metadata: $metadata,
            taxAmountMinor: $taxAmountMinor,
        );
    }

    public function postPayment(Payment $payment, ?int $actorId): FolioLine
    {
        $existing = FolioLine::query()
            ->where('payment_id', $payment->id)
            ->where('type', FolioLineType::Payment)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $this->assertOpen($payment->reservation);

        return $this->createLine(
            reservation: $payment->reservation,
            type: FolioLineType::Payment,
            description: 'Payment received · '.str_replace('_', ' ', $payment->method),
            quantityThousandths: 1000,
            unitAmountMinor: -$payment->amount_minor,
            actorId: $actorId,
            paymentId: $payment->id,
            metadata: ['payment_status' => $payment->status->value],
        );
    }

    public function postRefund(Payment $payment, ReservationChange $refund, ?int $actorId): FolioLine
    {
        $existing = FolioLine::query()
            ->where('metadata->refund_change_id', $refund->id)
            ->where('type', FolioLineType::Refund)
            ->first();
        if ($existing !== null) {
            return $existing;
        }
        $this->assertOpen($payment->reservation);

        return $this->createLine(
            reservation: $payment->reservation,
            type: FolioLineType::Refund,
            description: 'Refund completed · '.str_replace('_', ' ', $payment->method),
            quantityThousandths: 1000,
            unitAmountMinor: $refund->amount_minor,
            actorId: $actorId,
            paymentId: $payment->id,
            metadata: [
                'refund_change_id' => $refund->id,
                'refund_request_id' => $refund->parent_change_id,
                'reference' => $refund->reference,
            ],
        );
    }

    public function reverse(FolioLine $line, string $reason, ?int $actorId): FolioLine
    {
        return DB::transaction(function () use ($line, $reason, $actorId): FolioLine {
            $locked = FolioLine::query()->lockForUpdate()->findOrFail($line->id);
            $this->assertOpen($locked->reservation);
            $existing = FolioLine::query()->where('reverses_folio_line_id', $locked->id)->first();
            if ($existing !== null) {
                return $existing;
            }
            if ($locked->reverses_folio_line_id !== null) {
                throw new DomainException('A reversal entry cannot itself be reversed.');
            }

            return $this->createLine(
                reservation: $locked->reservation,
                type: $locked->type === FolioLineType::Payment ? FolioLineType::Refund : FolioLineType::Adjustment,
                description: 'Reversal · '.$locked->description,
                quantityThousandths: 1000,
                unitAmountMinor: -$locked->net_amount_minor,
                actorId: $actorId,
                paymentId: $locked->payment_id,
                reversesLineId: $locked->id,
                metadata: ['reason' => $reason, 'original_line_id' => $locked->id],
                taxAmountMinor: -$locked->tax_amount_minor,
            );
        }, 3);
    }

    /** @return array{status:string,booked_net_minor:int,booked_tax_minor:int,booked_total_minor:int,ledger_net_minor:int,ledger_tax_minor:int,ledger_gross_minor:int,ledger_delta_minor:int,balance_minor:int,closed_at:?string} */
    public function summary(Reservation $reservation): array
    {
        $lines = $reservation->folioLines()->get(['net_amount_minor', 'tax_amount_minor', 'gross_amount_minor', 'metadata']);
        $gross = (int) $lines->sum('gross_amount_minor');
        $includedInBookedTotal = (int) $lines
            ->filter(fn ($line): bool => $line instanceof FolioLine
                && data_get($line->metadata, 'included_in_booked_total') === true)
            ->sum('gross_amount_minor');
        $ledgerDelta = $gross - $includedInBookedTotal;

        return [
            'status' => ($reservation->folio_status ?? FolioStatus::Open)->value,
            'booked_net_minor' => $reservation->subtotal_minor,
            'booked_tax_minor' => $reservation->tax_minor,
            'booked_total_minor' => $reservation->total_minor,
            'ledger_net_minor' => (int) $lines->sum('net_amount_minor'),
            'ledger_tax_minor' => (int) $lines->sum('tax_amount_minor'),
            'ledger_gross_minor' => $gross,
            'ledger_delta_minor' => $ledgerDelta,
            'balance_minor' => $reservation->total_minor + $ledgerDelta,
            'closed_at' => $reservation->folio_closed_at?->toIso8601String(),
        ];
    }

    public function close(Reservation $reservation, ?int $actorId): Reservation
    {
        return DB::transaction(function () use ($reservation, $actorId): Reservation {
            $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            if ($locked->folio_status === FolioStatus::Closed) {
                return $locked;
            }
            if ($locked->status !== ReservationStatus::CheckedOut) {
                throw new DomainException('A folio can only be closed after checkout.');
            }
            if ($this->summary($locked)['balance_minor'] !== 0) {
                throw new DomainException('Settle or adjust the folio balance before closing it.');
            }

            $locked->update([
                'folio_status' => FolioStatus::Closed,
                'folio_closed_at' => now(),
                'folio_closed_by' => $actorId,
            ]);

            return $locked->fresh(['folioClosedBy']);
        }, 3);
    }

    public function reopen(Reservation $reservation): Reservation
    {
        return DB::transaction(function () use ($reservation): Reservation {
            $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            if ($locked->folio_status === FolioStatus::Open) {
                return $locked;
            }
            $locked->update([
                'folio_status' => FolioStatus::Open,
                'folio_closed_at' => null,
                'folio_closed_by' => null,
            ]);

            return $locked;
        }, 3);
    }

    /** @param array<string, mixed> $metadata */
    private function createLine(
        Reservation $reservation,
        FolioLineType $type,
        string $description,
        int $quantityThousandths,
        int $unitAmountMinor,
        ?int $actorId,
        ?string $paymentId = null,
        ?string $reversesLineId = null,
        array $metadata = [],
        int $taxAmountMinor = 0,
    ): FolioLine {
        $netAmountMinor = $this->money->lineAmount($unitAmountMinor, $quantityThousandths);
        $grossAmountMinor = $netAmountMinor + $taxAmountMinor;

        return FolioLine::query()->create([
            'reservation_id' => $reservation->id,
            'payment_id' => $paymentId,
            'reverses_folio_line_id' => $reversesLineId,
            'type' => $type,
            'description' => trim($description),
            'quantity' => number_format($quantityThousandths / 1000, 3, '.', ''),
            'unit_amount_minor' => $unitAmountMinor,
            'net_amount_minor' => $netAmountMinor,
            'tax_amount_minor' => $taxAmountMinor,
            'gross_amount_minor' => $grossAmountMinor,
            'amount_minor' => $grossAmountMinor,
            'currency' => $reservation->currency,
            'posted_at' => now(),
            'created_by' => $actorId,
            'metadata' => $metadata,
        ]);
    }

    private function assertOpen(Reservation $reservation): void
    {
        if ($reservation->folio_status === FolioStatus::Closed) {
            throw new DomainException('The folio is closed. Reopen it before posting entries.');
        }
    }
}
