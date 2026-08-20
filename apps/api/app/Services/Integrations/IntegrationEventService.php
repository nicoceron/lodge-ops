<?php

namespace App\Services\Integrations;

use App\Contracts\Integrations\InboundWebhookPort;
use App\Contracts\Integrations\SecretReferenceResolver;
use App\Data\Integrations\IntegrationServiceIdentity;
use App\Jobs\ProcessIntegrationEventJob;
use App\Models\IntegrationConnection;
use App\Models\IntegrationConnectionCapability;
use App\Models\IntegrationDeadLetter;
use App\Models\IntegrationEvent;
use App\Models\IntegrationReconciliation;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class IntegrationEventService
{
    public function __construct(
        private readonly EndpointKeyService $endpointKeys,
        private readonly SecretReferenceResolver $secrets,
        private readonly StandardWebhookVerifier $signatures,
        private readonly CapabilityPortRegistry $ports,
    ) {}

    /** @param array<string,string> $headers */
    public function receive(string $endpointKey, string $rawBody, array $headers): IntegrationEvent
    {
        $connection = $this->endpointKeys->resolveConnection($endpointKey);
        if (! $connection->is_enabled || $connection->revoked_at !== null || ! in_array('webhook.inbound', $connection->capabilities ?? [], true)) {
            throw new RuntimeException('The webhook endpoint is unavailable.');
        }
        if (! IntegrationConnectionCapability::query()->where('integration_connection_id', $connection->id)
            ->where('capability', 'webhook.inbound')->where('direction', 'inbound')->where('state', 'enabled')->exists()) {
            throw new RuntimeException('The webhook capability is unavailable.');
        }
        $reference = data_get($connection->configuration, 'webhook_signing_secret_reference');
        if (! is_string($reference) || $reference === '') {
            throw new RuntimeException('The webhook signing secret reference is unavailable.');
        }
        $this->signatures->verify($rawBody, $headers, $this->secrets->resolve($reference));
        $payload = json_decode($rawBody, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            throw new RuntimeException('The verified webhook body is not a JSON object.');
        }
        $externalId = (string) ($headers['webhook-id'] ?? '');
        $eventType = (string) ($payload['type'] ?? 'unknown');
        $version = (string) ($payload['version'] ?? '1');
        $checksum = hash('sha256', $rawBody);
        $account = $payload['account_id'] ?? null;
        $unknownAccount = is_string($account) && $account !== '' && $account !== $connection->external_account_id;
        try {
            $event = DB::transaction(function () use ($connection, $payload, $externalId, $eventType, $version, $checksum, $unknownAccount): IntegrationEvent {
                $event = IntegrationEvent::query()->create([
                    'integration_connection_id' => $connection->id,
                    'property_id' => $connection->property_id,
                    'capability' => 'webhook.inbound',
                    'external_id' => $externalId,
                    'event_type' => $eventType,
                    'external_version' => $version,
                    'raw_checksum' => $checksum,
                    'safe_snapshot' => $this->safeSnapshot($payload),
                    'disposition' => $unknownAccount ? 'unmatched' : 'received',
                    'occurred_at' => isset($payload['occurred_at']) ? $payload['occurred_at'] : null,
                    'received_at' => now(),
                    'processed_at' => $unknownAccount ? now() : null,
                    'last_error' => $unknownAccount ? 'Signed event account does not match the endpoint connection.' : null,
                ]);
                if ($unknownAccount) {
                    IntegrationReconciliation::query()->create([
                        'integration_connection_id' => $connection->id,
                        'property_id' => $connection->property_id,
                        'kind' => 'unknown_external_account',
                        'external_key' => $externalId,
                        'status' => 'open',
                        'reason_code' => 'account_mismatch',
                        'safe_facts' => ['event_id' => $event->id, 'raw_checksum' => $checksum],
                    ]);
                }

                return $event;
            }, 3);
        } catch (QueryException $exception) {
            $existing = IntegrationEvent::query()->where('integration_connection_id', $connection->id)
                ->where(fn ($query) => $query->where('raw_checksum', $checksum)->orWhere(function ($query) use ($externalId, $eventType, $version): void {
                    $query->where('external_id', $externalId)->where('event_type', $eventType)->where('external_version', $version);
                }))->first();
            if ($existing === null) {
                throw $exception;
            }
            if ($existing->raw_checksum !== $checksum
                || $existing->external_id !== $externalId
                || $existing->event_type !== $eventType
                || $existing->external_version !== $version) {
                IntegrationReconciliation::query()->firstOrCreate([
                    'integration_connection_id' => $connection->id,
                    'kind' => 'event_identity_collision',
                    'external_key' => $externalId,
                    'local_key' => $existing->id,
                    'status' => 'open',
                ], [
                    'property_id' => $connection->property_id,
                    'reason_code' => 'signed_event_identity_reused',
                    'safe_facts' => [
                        'existing_event_id' => $existing->id,
                        'existing_checksum' => $existing->raw_checksum,
                        'received_checksum' => $checksum,
                    ],
                ]);
            }

            return $existing;
        }
        $connection->update(['last_event_at' => now()]);
        if (! $unknownAccount) {
            DB::afterCommit(fn () => ProcessIntegrationEventJob::dispatch($event->tenant_id, $event->id)->onQueue('integrations'));
        }

        return $event;
    }

    public function process(IntegrationEvent $event): void
    {
        $claimed = DB::transaction(function () use ($event): ?IntegrationEvent {
            $candidate = IntegrationEvent::query()->lockForUpdate()->findOrFail($event->id);
            if (! in_array($candidate->disposition, ['received', 'retryable'], true)) {
                return null;
            }
            $connection = IntegrationConnection::query()->findOrFail($candidate->integration_connection_id);
            if (! $connection->is_enabled || $connection->revoked_at !== null) {
                $candidate->update(['disposition' => 'blocked', 'last_error' => 'Connection disabled or revoked before event processing.']);

                return null;
            }
            if (! IntegrationConnectionCapability::query()->where('integration_connection_id', $connection->id)
                ->where('capability', 'webhook.inbound')->where('direction', 'inbound')->where('state', 'enabled')->exists()) {
                $candidate->update(['disposition' => 'blocked', 'last_error' => 'Webhook capability disabled before event processing.']);

                return null;
            }
            $candidate->update(['disposition' => 'processing', 'attempt' => $candidate->attempt + 1, 'last_error' => null]);

            return $candidate->fresh('connection');
        }, 3);
        if ($claimed === null) {
            return;
        }
        try {
            $port = $this->ports->for($claimed->connection, 'webhook.inbound');
            if (! $port instanceof InboundWebhookPort) {
                throw new RuntimeException('Registered port does not implement InboundWebhookPort.');
            }
            $identity = new IntegrationServiceIdentity($claimed->tenant_id, $claimed->property_id, $claimed->integration_connection_id, ['webhook.inbound'], 'event:'.$claimed->id);
            $port->consume($claimed->connection, $claimed, $identity, hash('sha256', $claimed->tenant_id."\0event\0".$claimed->id));
            $claimed->update(['disposition' => 'processed', 'processed_at' => now(), 'last_error' => null]);
            IntegrationDeadLetter::query()->where('integration_event_id', $claimed->id)->update([
                'status' => 'resolved', 'resolved_at' => now(), 'resolution' => 'Replay succeeded.',
            ]);
            $claimed->connection->update(['last_success_at' => now(), 'health_status' => 'healthy', 'last_error' => null]);
        } catch (Throwable $exception) {
            $claimed->update(['disposition' => 'retryable', 'last_error' => SafeIntegrationError::from($exception)]);
            throw $exception;
        }
    }

    public function deadLetter(IntegrationEvent $event, Throwable|string $error): IntegrationDeadLetter
    {
        return DB::transaction(function () use ($event, $error): IntegrationDeadLetter {
            $event->update(['disposition' => 'dead_letter', 'processed_at' => now(), 'last_error' => SafeIntegrationError::from($error)]);

            return IntegrationDeadLetter::query()->updateOrCreate(
                ['integration_event_id' => $event->id],
                [
                    'integration_connection_id' => $event->integration_connection_id,
                    'property_id' => $event->property_id,
                    'reason_code' => 'event_retry_exhausted',
                    'safe_error' => SafeIntegrationError::from($error),
                    'status' => 'open',
                    'resolved_at' => null,
                    'resolution' => null,
                ],
            );
        }, 3);
    }

    public function replay(IntegrationEvent $event, ?int $actorId, string $reason): IntegrationEvent
    {
        if (in_array($event->disposition, ['received', 'processing', 'retryable'], true)) {
            throw new DomainException('The original event is still active.');
        }
        DB::transaction(function () use ($event, $actorId, $reason): void {
            $event->update(['disposition' => 'received', 'processed_at' => null, 'last_error' => null]);
            IntegrationOperationRecorder::record($event->connection, 'event_replayed', $actorId, $reason, ['event_id' => $event->id]);
            DB::afterCommit(fn () => ProcessIntegrationEventJob::dispatch($event->tenant_id, $event->id)->onQueue('integrations'));
        }, 3);

        return $event->fresh();
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function safeSnapshot(array $payload): array
    {
        return collect($payload)->only(['id', 'type', 'version', 'account_id', 'occurred_at', 'subject', 'action'])
            ->map(fn ($value) => is_scalar($value) || $value === null ? $value : '[omitted]')->all();
    }
}
