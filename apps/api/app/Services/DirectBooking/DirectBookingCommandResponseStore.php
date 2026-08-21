<?php

namespace App\Services\DirectBooking;

use App\Models\DirectBookingCommandResponse;
use App\Models\DirectBookingOrder;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

final class DirectBookingCommandResponseStore
{
    /** @param array<string, mixed> $data */
    public function complete(DirectBookingOrder $order, array $data, int $status, string $retryIdentity): void
    {
        DB::transaction(function () use ($order, $data, $status, $retryIdentity): void {
            if (app()->environment('testing') && config('direct-booking.testing.fail_command_completion') === $retryIdentity) {
                throw new RuntimeException('Injected direct-booking command completion crash.');
            }
            $record = DirectBookingCommandResponse::query()
                ->where('idempotency_key', $retryIdentity)
                ->lockForUpdate()
                ->firstOrFail();
            $correlation = (string) request()->attributes->get('direct_booking_correlation_id');
            $body = response()->json(['data' => $data], $status)->getContent();
            if (! is_string($body)) {
                throw new LogicException('The direct-booking command response could not be encoded.');
            }
            if ($record->status_code !== null) {
                if ($record->status_code !== $status || ! hash_equals((string) $record->response_body_encrypted, $body)) {
                    throw new LogicException('The durable direct-booking command result conflicts with its replay.');
                }

                return;
            }
            $lockedOrder = DirectBookingOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $record->forceFill([
                'direct_booking_order_id' => $lockedOrder->id,
                'response_token_hash' => $lockedOrder->token_hash,
                'status_code' => $status,
                'response_body_encrypted' => $body,
                'response_headers' => [
                    'Cache-Control' => 'no-store, private',
                    'X-Correlation-ID' => $correlation,
                ],
                'lease_expires_at' => now(),
            ])->save();
        }, 3);
    }
}
