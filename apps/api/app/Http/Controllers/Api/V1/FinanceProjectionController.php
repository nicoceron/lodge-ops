<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DepositStatus;
use App\Enums\FolioLineType;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\Reservation;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class FinanceProjectionController extends Controller
{
    public function __invoke(Request $request, TenantContext $context): JsonResponse
    {
        $this->authorize('viewFinance', Payment::class);
        $now = CarbonImmutable::now($context->tenant()->timezone);
        $start = $now->startOfMonth()->utc();
        $end = $now->addMonth()->startOfMonth()->utc();
        $bookableStatuses = [
            ReservationStatus::Confirmed,
            ReservationStatus::CheckedIn,
            ReservationStatus::CheckedOut,
        ];
        $reservations = Reservation::query()
            ->with('payments:id,reservation_id,status,amount_minor,processed_at')
            ->whereIn('status', $bookableStatuses)
            ->where('starts_at', '>=', $start)
            ->where('starts_at', '<', $end)
            ->orderByDesc('starts_at')
            ->get();
        $bookedRevenue = (int) $reservations->sum('total_minor');
        $cashCollected = (int) Payment::query()
            ->where('status', PaymentStatus::Succeeded)
            ->where('processed_at', '>=', $start)
            ->where('processed_at', '<', $end)
            ->sum('amount_minor');
        $receivables = (int) $reservations->sum(fn (Reservation $reservation) => $this->balance($reservation));
        $deposits = Deposit::query()->get();
        $folioLines = FolioLine::query()
            ->where('posted_at', '>=', $start)
            ->where('posted_at', '<', $end)
            ->get();

        return response()->json([
            'data' => [
                'currency' => $context->tenant()->currency,
                'timezone' => $context->tenant()->timezone,
                'period' => [
                    'start' => $start->toIso8601String(),
                    'end' => $end->toIso8601String(),
                    'label' => $now->format('F Y'),
                ],
                'summary' => [
                    'booked_revenue_minor' => $bookedRevenue,
                    'cash_collected_minor' => $cashCollected,
                    'receivables_minor' => $receivables,
                    'overdue_deposits_minor' => (int) $deposits
                        ->filter(fn (Deposit $deposit) => $deposit->status === DepositStatus::Due && $deposit->due_at?->isPast())
                        ->sum('amount_minor'),
                    'collection_percent' => $bookedRevenue > 0 ? round(($cashCollected / $bookedRevenue) * 100, 1) : 0.0,
                ],
                'deposits' => [
                    'due_count' => $deposits->where('status', DepositStatus::Due)->count(),
                    'due_minor' => (int) $deposits->where('status', DepositStatus::Due)->sum('amount_minor'),
                    'paid_count' => $deposits->where('status', DepositStatus::Paid)->count(),
                    'paid_minor' => (int) $deposits->where('status', DepositStatus::Paid)->sum('amount_minor'),
                    'overdue_count' => $deposits->filter(fn (Deposit $deposit) => $deposit->status === DepositStatus::Due && $deposit->due_at?->isPast())->count(),
                ],
                'folio' => [
                    'charges_minor' => $this->folioAmount($folioLines, FolioLineType::Charge),
                    'payments_minor' => abs($this->folioAmount($folioLines, FolioLineType::Payment)),
                    'refunds_minor' => abs($this->folioAmount($folioLines, FolioLineType::Refund)),
                    'adjustments_minor' => $this->folioAmount($folioLines, FolioLineType::Adjustment),
                ],
                'revenue_series' => $this->revenueSeries($now, $bookableStatuses),
                'channels' => $this->channels($reservations),
                'recent_folios' => $reservations->take(10)->map(fn (Reservation $reservation): array => [
                    'reservation_id' => $reservation->id,
                    'confirmation_number' => $reservation->confirmation_number,
                    'status' => $reservation->status->value,
                    'total_minor' => $reservation->total_minor,
                    'paid_minor' => $this->paid($reservation),
                    'balance_minor' => $this->balance($reservation),
                ])->values(),
            ],
        ]);
    }

    /** @param Collection<int, FolioLine> $lines */
    private function folioAmount(Collection $lines, FolioLineType $type): int
    {
        return (int) $lines->where('type', $type)->sum('amount_minor');
    }

    /** @param list<ReservationStatus> $statuses @return list<array{label: string, value_minor: int}> */
    private function revenueSeries(CarbonImmutable $now, array $statuses): array
    {
        return collect(range(6, 0))
            ->map(function (int $monthsAgo) use ($now, $statuses): array {
                $month = $now->subMonths($monthsAgo);
                $start = $month->startOfMonth()->utc();
                $end = $month->addMonth()->startOfMonth()->utc();

                return [
                    'label' => $month->format('M'),
                    'value_minor' => (int) Reservation::query()
                        ->whereIn('status', $statuses)
                        ->where('starts_at', '>=', $start)
                        ->where('starts_at', '<', $end)
                        ->sum('total_minor'),
                ];
            })
            ->all();
    }

    /** @param Collection<int, Reservation> $reservations @return list<array<string, mixed>> */
    private function channels(Collection $reservations): array
    {
        return $reservations
            ->groupBy(fn (Reservation $reservation) => $reservation->source ?: 'Direct')
            ->map(function (Collection $channelReservations, string $channel): array {
                $revenue = (int) $channelReservations->sum('total_minor');
                $collected = (int) $channelReservations->sum(fn (Reservation $reservation) => $this->paid($reservation));

                return [
                    'channel' => $channel,
                    'bookings' => $channelReservations->count(),
                    'revenue_minor' => $revenue,
                    'collected_minor' => $collected,
                    'collection_percent' => $revenue > 0 ? round(($collected / $revenue) * 100, 1) : 0.0,
                ];
            })
            ->sortByDesc('revenue_minor')
            ->values()
            ->all();
    }

    private function paid(Reservation $reservation): int
    {
        return (int) $reservation->payments
            ->where('status', PaymentStatus::Succeeded)
            ->sum('amount_minor');
    }

    private function balance(Reservation $reservation): int
    {
        return max(0, $reservation->total_minor - $this->paid($reservation));
    }
}
