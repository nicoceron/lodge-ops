<?php

namespace App\Services\Payments;

use App\Enums\ProviderEventState;
use App\Models\PaymentAttempt;
use App\Models\ProviderEvent;
use Illuminate\Support\Str;

final class ReconcileProviderPayment
{
    public function __construct(private readonly ProcessProviderEvent $processor) {}

    public function handle(PaymentAttempt $attempt): ProviderEvent
    {
        if ($attempt->provider_payment_id === null) {
            throw new \DomainException('The provider payment identifier is not known yet.');
        }
        $event = ProviderEvent::query()->create([
            'integration_connection_id' => $attempt->integration_connection_id,
            'provider' => $attempt->provider,
            'environment' => $attempt->environment,
            'provider_account' => $attempt->provider_account,
            'delivery_id' => 'reconcile-'.Str::uuid(),
            'topic' => 'payment',
            'event_type' => 'payment.reconciliation',
            'action' => 'reconcile',
            'resource_id' => $attempt->provider_payment_id,
            'signature_valid' => true,
            'received_at' => now(),
            'processing_state' => ProviderEventState::Received,
            'raw_body_checksum' => hash('sha256', 'reconcile:'.$attempt->id.':'.now()->toIso8601String()),
            'private_payload' => ['source' => 'authenticated_manual_reconciliation', 'attempt_id' => $attempt->id],
            'sanitized_headers' => [],
        ]);

        return $this->processor->handle($event);
    }
}
