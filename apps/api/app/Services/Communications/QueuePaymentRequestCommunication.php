<?php

namespace App\Services\Communications;

use App\Enums\CommunicationPurpose;
use App\Models\Communication;
use App\Models\PaymentRequest;
use App\Services\Automation\OutboxRecorder;
use Illuminate\Support\Facades\DB;

final class QueuePaymentRequestCommunication
{
    public function __construct(private readonly OutboxRecorder $outbox) {}

    public function handle(PaymentRequest $request, string $plainToken, ?int $actorId): ?Communication
    {
        $request->loadMissing('reservation.primaryGuest');
        $reservation = $request->reservation;
        if ($reservation->primaryGuest?->email === null) {
            return null;
        }
        $url = rtrim((string) config('app.url'), '/').'/pay/'.rawurlencode($plainToken);
        $automationKey = 'payment-request:'.$request->id.':'.$request->access_token_hash;

        return DB::transaction(function () use ($request, $reservation, $url, $automationKey, $actorId): Communication {
            $communication = Communication::query()->firstOrCreate(
                ['automation_key' => $automationKey],
                [
                    'property_id' => $request->property_id,
                    'guest_id' => $reservation->primary_guest_id,
                    'reservation_id' => $reservation->id,
                    'channel' => 'email',
                    'direction' => 'outbound',
                    'purpose' => CommunicationPurpose::PaymentRequest->value,
                    'status' => 'queued',
                    'subject' => 'Secure payment request for '.$reservation->confirmation_number,
                    'body' => "Complete your secure {$request->source_currency} payment of {$request->source_amount_minor}: {$url}",
                    'metadata' => [
                        'payment_request_id' => $request->id,
                        'requested_by' => $actorId,
                    ],
                ],
            );
            if ($communication->wasRecentlyCreated) {
                $this->outbox->record('communication', $communication->id, 'communication.queued', [
                    'communication_id' => $communication->id,
                    'channel' => 'email',
                ]);
            }

            return $communication;
        }, 3);
    }
}
