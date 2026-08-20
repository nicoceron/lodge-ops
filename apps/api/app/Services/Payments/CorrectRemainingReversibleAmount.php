<?php

namespace App\Services\Payments;

use App\Enums\PaymentOrigin;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationChange;
use App\Models\User;
use App\Services\FolioService;
use App\Services\RequestRefund;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

final class CorrectRemainingReversibleAmount
{
    public function __construct(
        private readonly FinancialCommandExecutor $commands,
        private readonly FrontDeskPaymentGuard $guard,
        private readonly RequestRefund $requests,
        private readonly FolioService $folio,
    ) {}

    public function handle(User $actor, Payment $payment, string $reason, string $idempotencyKey): ReservationChange
    {
        $tenantId = app(TenantContext::class)->tenant()->id;
        $payload = ['payment_id' => $payment->id, 'reason' => trim($reason)];

        /** @var ReservationChange $result */
        $result = $this->commands->run($tenantId, self::class, $idempotencyKey, $payload, function () use ($actor, $payment, $reason): ReservationChange {
            $snapshot = Payment::query()->findOrFail($payment->id);
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($snapshot->reservation_id);
            $locked = Payment::query()->lockForUpdate()->findOrFail($snapshot->id);
            $this->guard->resolveException($actor, $reservation->property_id);
            if ($locked->origin !== PaymentOrigin::Manual) {
                throw ValidationException::withMessages(['payment' => 'Provider-origin reversible amounts require the provider dispute/refund path.']);
            }
            $completed = (int) $reservation->changes()->where('type', 'refund_completed')->where('status', 'completed')
                ->where('metadata->payment_id', $locked->id)->sum('amount_minor');
            $openRequests = (int) $reservation->changes()->where('type', 'refund_requested')->where('status', 'requested')->get()
                ->reject(fn (ReservationChange $change): bool => $change->events()->where('type', 'refund_completed')->exists())
                ->sum('amount_minor');
            $paymentRemaining = max(0, $locked->amount_minor - $completed - $openRequests);
            $availableCredit = max(0, -$this->folio->summary($reservation)['balance_minor'] - $openRequests);
            $remaining = min($paymentRemaining, $availableCredit);
            if ($remaining === 0) {
                throw ValidationException::withMessages(['payment' => 'No reversible amount remains on this payment.']);
            }

            return $this->requests->handle($reservation, $locked, $remaining, $reason, $actor->id);
        });

        return $result;
    }
}
