<?php

namespace App\Services\Payments;

use App\Contracts\Payments\PaymentGatewayFactory;
use App\Data\Payments\WebhookRequest;
use App\Enums\ProviderEventState;
use App\Jobs\ProcessProviderEventJob;
use App\Models\IntegrationConnection;
use App\Models\ProviderEvent;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class ReceiveProviderWebhook
{
    public function __construct(private readonly PaymentGatewayFactory $gateways) {}

    /** @param array<string, string> $headers @param array<string, string> $query */
    public function handle(string $webhookKey, string $rawBody, array $headers, array $query): ProviderEvent
    {
        $connection = IntegrationConnection::withoutGlobalScopes()
            ->where('type', 'payment')->where('configuration->webhook_key', $webhookKey)->firstOrFail();
        app(TenantContext::class)->set(Tenant::query()->findOrFail($connection->tenant_id));
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
            return ProviderEvent::query()->where(fn ($query) => $query->where('delivery_id', $verified->deliveryId)->orWhere('raw_body_checksum', $checksum))->firstOrFail();
        }

        DB::afterCommit(fn () => ProcessProviderEventJob::dispatch($event->tenant_id, $event->id)->onQueue('provider-events'));

        return $event;
    }
}
