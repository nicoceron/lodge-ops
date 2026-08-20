<?php

namespace App\Services\Payments;

use App\Contracts\Payments\PaymentGatewayFactory;
use App\Data\Payments\WebhookRequest;
use App\Enums\ProviderEventState;
use App\Jobs\ProcessProviderEventJob;
use App\Models\ProviderEvent;
use App\Services\Integrations\EndpointKeyService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReceiveProviderWebhook
{
    public function __construct(private readonly PaymentGatewayFactory $gateways, private readonly EndpointKeyService $endpointKeys) {}

    /** @param array<string, string> $headers @param array<string, string> $query */
    public function handle(string $webhookKey, string $rawBody, array $headers, array $query): ProviderEvent
    {
        $connection = $this->endpointKeys->resolveConnection($webhookKey);
        abort_unless($connection->type === 'payment', 404);
        $verified = $this->gateways->for($connection)->verifyWebhook(new WebhookRequest($rawBody, $headers, $query));
        $checksum = hash('sha256', $rawBody);

        try {
            $event = DB::transaction(fn (): ProviderEvent => ProviderEvent::query()->create([
                'integration_connection_id' => $connection->id,
                'provider' => 'mercado_pago',
                'environment' => data_get($connection->configuration, 'environment', 'sandbox'),
                'provider_account' => data_get($connection->configuration, 'provider_account'),
                'delivery_id' => $verified->deliveryId,
                'topic' => $verified->topic,
                'event_type' => $verified->type,
                'action' => $verified->action,
                'resource_id' => $verified->resourceId,
                'signature_valid' => true,
                'received_at' => now(),
                'provider_created_at' => $verified->providerCreatedAt,
                'processing_state' => ProviderEventState::Received,
                'raw_body_checksum' => $checksum,
                'private_payload' => $verified->payload,
                'sanitized_headers' => ['x-request-id' => $verified->deliveryId],
            ]));
        } catch (QueryException) {
            $original = ProviderEvent::query()
                ->where('provider', 'mercado_pago')
                ->where('environment', data_get($connection->configuration, 'environment', 'sandbox'))
                ->where('provider_account', data_get($connection->configuration, 'provider_account'))
                ->where(fn ($query) => $query->where('delivery_id', $verified->deliveryId)->orWhere('raw_body_checksum', $checksum))
                ->firstOrFail();

            return ProviderEvent::query()->create([
                'integration_connection_id' => $connection->id,
                'duplicate_of_id' => $original->id,
                'provider' => 'mercado_pago',
                'environment' => data_get($connection->configuration, 'environment', 'sandbox'),
                'provider_account' => data_get($connection->configuration, 'provider_account'),
                'delivery_id' => 'duplicate-'.Str::uuid(),
                'topic' => $verified->topic,
                'event_type' => $verified->type,
                'action' => $verified->action,
                'resource_id' => $verified->resourceId,
                'signature_valid' => true,
                'received_at' => now(),
                'provider_created_at' => $verified->providerCreatedAt,
                'processing_state' => ProviderEventState::Duplicate,
                'raw_body_checksum' => hash('sha256', $checksum.':duplicate:'.Str::uuid()),
                'private_payload' => ['duplicate_of_id' => $original->id],
                'sanitized_headers' => ['x-request-id' => $verified->deliveryId],
                'processed_at' => now(),
            ]);
        }

        DB::afterCommit(fn () => ProcessProviderEventJob::dispatch($event->tenant_id, $event->id)->onQueue('provider-events'));

        return $event;
    }
}
