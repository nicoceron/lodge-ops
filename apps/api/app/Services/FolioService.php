<?php

namespace App\Services;

use App\Enums\FolioLineType;
use App\Exceptions\CommercialWorkflowException as DomainException;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\Reservation;
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
    ): FolioLine {
        if (! in_array($type, [FolioLineType::Charge, FolioLineType::Adjustment], true)) {
            throw new DomainException('Manual folio entries must be charges or adjustments.');
        }

        return $this->createLine(
            reservation: $reservation,
            type: $type,
            description: $description,
            quantityThousandths: $quantityThousandths,
            unitAmountMinor: $unitAmountMinor,
            actorId: $actorId,
            metadata: $metadata,
        );
    }

    public function postPayment(Payment $payment, ?int $actorId): FolioLine
    {
        $existing = FolioLine::query()
            ->where('payment_id', $payment->id)
            ->where('type', FolioLineType::Payment)
            ->first();

        return $existing ?? $this->createLine(
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

    public function reverse(FolioLine $line, string $reason, ?int $actorId): FolioLine
    {
        return DB::transaction(function () use ($line, $reason, $actorId): FolioLine {
            $locked = FolioLine::query()->lockForUpdate()->findOrFail($line->id);
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
                unitAmountMinor: -$locked->amount_minor,
                actorId: $actorId,
                paymentId: $locked->payment_id,
                reversesLineId: $locked->id,
                metadata: ['reason' => $reason, 'original_line_id' => $locked->id],
            );
        }, 3);
    }

    /** @return array{booked_total_minor:int,ledger_delta_minor:int,balance_minor:int} */
    public function summary(Reservation $reservation): array
    {
        $delta = (int) $reservation->folioLines()->sum('amount_minor');

        return [
            'booked_total_minor' => $reservation->total_minor,
            'ledger_delta_minor' => $delta,
            'balance_minor' => $reservation->total_minor + $delta,
        ];
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
    ): FolioLine {
        return FolioLine::query()->create([
            'reservation_id' => $reservation->id,
            'payment_id' => $paymentId,
            'reverses_folio_line_id' => $reversesLineId,
            'type' => $type,
            'description' => trim($description),
            'quantity' => number_format($quantityThousandths / 1000, 3, '.', ''),
            'unit_amount_minor' => $unitAmountMinor,
            'amount_minor' => $this->money->lineAmount($unitAmountMinor, $quantityThousandths),
            'currency' => $reservation->currency,
            'posted_at' => now(),
            'created_by' => $actorId,
            'metadata' => $metadata,
        ]);
    }
}
