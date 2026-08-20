<?php

namespace App\Services\Payments;

use App\Enums\PaymentOrigin;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationChange;
use App\Models\User;
use App\Services\RequestRefund;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

final class RequestManualExternalRefund
{
    public function __construct(
        private readonly FinancialCommandExecutor $commands,
        private readonly FrontDeskPaymentGuard $guard,
        private readonly RequestRefund $refunds,
    ) {}

    public function handle(User $actor, Payment $payment, int $amountMinor, string $reason, string $idempotencyKey): ReservationChange
    {
        $tenantId = app(TenantContext::class)->tenant()->id;
        $payload = ['payment_id' => $payment->id, 'amount_minor' => $amountMinor, 'reason' => trim($reason)];

        /** @var ReservationChange $result */
        $result = $this->commands->run($tenantId, self::class, $idempotencyKey, $payload, function () use ($actor, $payment, $amountMinor, $reason): ReservationChange {
            $snapshot = Payment::query()->findOrFail($payment->id);
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($snapshot->reservation_id);
            $locked = Payment::query()->lockForUpdate()->findOrFail($snapshot->id);
            $this->guard->resolveException($actor, $reservation->property_id);
            if ($locked->origin !== PaymentOrigin::Manual) {
                throw ValidationException::withMessages(['payment' => 'Provider-origin refunds must use authoritative provider execution or recovery.']);
            }

            return $this->refunds->handle($reservation, $locked, $amountMinor, $reason, $actor->id);
        });

        return $result;
    }
}
