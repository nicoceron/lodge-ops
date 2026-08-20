<?php

namespace App\Services\Communications;

use App\Jobs\ProcessCommunicationDeliveryEvent;
use App\Models\CommunicationDeliveryEvent;
use App\Models\CommunicationProviderConnection;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Throwable;

final class ReceiveCommunicationWebhook
{
    public function __construct(
        private readonly SecretReferenceResolver $secrets,
        private readonly SvixSignatureVerifier $signatures,
    ) {}

    /** @param array<string, string> $headers */
    public function handle(string $endpointKey, string $rawBody, array $headers): CommunicationDeliveryEvent
    {
        $connection = CommunicationProviderConnection::withoutGlobalScopes()
            ->where('endpoint_key_hash', hash('sha256', $endpointKey))
            ->where('is_enabled', true)->whereNull('revoked_at')->firstOrFail();

        $providerEventId = null;
        foreach ($connection->webhook_secret_refs ?? [] as $reference) {
            try {
                $providerEventId = $this->signatures->verify($rawBody, $headers, $this->secrets->resolve((string) $reference));
                break;
            } catch (DomainException) {
                continue;
            }
        }
        if ($providerEventId === null) {
            throw new DomainException('Invalid provider notification.');
        }

        try {
            $payload = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new DomainException('Invalid provider notification.');
        }
        if (! is_array($payload) || ! is_string($payload['type'] ?? null)) {
            throw new DomainException('Invalid provider notification.');
        }

        $tenantContext = app(TenantContext::class);
        $previousTenant = $tenantContext->check() ? $tenantContext->tenant() : null;
        $previousMembership = $tenantContext->membership();
        $tenantContext->clear();

        try {
            $tenantContext->set(Tenant::query()->findOrFail($connection->tenant_id));
            $normalized = $this->normalize($payload);
            try {
                $event = CommunicationDeliveryEvent::query()->create([
                    'property_id' => $connection->property_id,
                    'communication_provider_connection_id' => $connection->id,
                    'provider' => $connection->provider,
                    'provider_account_id' => $connection->account_id,
                    'provider_event_id' => $providerEventId,
                    'provider_message_id' => $normalized['provider_message_id'],
                    'type' => $normalized['type'],
                    'occurred_at' => $normalized['occurred_at'],
                    'received_at' => now(),
                    'raw_body_checksum' => hash('sha256', $rawBody),
                    'normalized_payload' => $normalized['payload'],
                    'processing_state' => 'pending',
                ]);
            } catch (QueryException $exception) {
                $event = CommunicationDeliveryEvent::query()
                    ->where('communication_provider_connection_id', $connection->id)
                    ->where('provider_event_id', $providerEventId)->first();
                if ($event === null) {
                    throw $exception;
                }
            }

            $this->enqueue($event);

            return $event;
        } finally {
            $tenantContext->clear();
            if ($previousTenant !== null) {
                $tenantContext->set($previousTenant, $previousMembership);
            }
        }
    }

    private function enqueue(CommunicationDeliveryEvent $event): void
    {
        if ($event->processed_at !== null || ! in_array($event->processing_state, ['pending', 'failed'], true)) {
            return;
        }
        if ($event->processing_state === 'failed') {
            $event->forceFill(['processing_state' => 'pending', 'processing_error' => null])->save();
        }

        try {
            ProcessCommunicationDeliveryEvent::dispatch($event->tenant_id, $event->id)
                ->onQueue((string) config('communications.provider.event_queue', 'provider-events'));
        } catch (Throwable $exception) {
            // The immutable event remains pending for the scheduled sweeper or a provider redelivery.
            report($exception);
        }
    }

    /** @param array<string, mixed> $payload @return array{provider_message_id:?string,type:string,occurred_at:CarbonImmutable,payload:array<string,mixed>} */
    private function normalize(array $payload): array
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $providerMessageId = $data['email_id'] ?? null;
        $occurredAt = is_string($payload['created_at'] ?? null)
            ? CarbonImmutable::parse($payload['created_at'])->utc() : CarbonImmutable::now('UTC');
        $recipients = is_array($data['to'] ?? null) ? $data['to'] : [];

        return [
            'provider_message_id' => is_string($providerMessageId) ? $providerMessageId : null,
            'type' => mb_substr($payload['type'], 0, 48),
            'occurred_at' => $occurredAt,
            'payload' => array_filter([
                'recipient_hashes' => array_values(array_map(
                    static fn (mixed $recipient): string => hash('sha256', mb_strtolower(trim((string) $recipient))),
                    $recipients,
                )),
                'bounce_type' => is_string(data_get($data, 'bounce.type')) ? mb_substr(data_get($data, 'bounce.type'), 0, 40) : null,
                'reason_code' => is_string($data['reason'] ?? null) ? mb_substr($data['reason'], 0, 80) : null,
            ], static fn (mixed $value): bool => $value !== null),
        ];
    }
}
