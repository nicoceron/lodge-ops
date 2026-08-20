<?php

namespace App\Services\Integrations;

use App\Contracts\Integrations\AccountingJournalExportPort;
use App\Contracts\Integrations\OutboundWebhookPort;
use App\Contracts\Integrations\ReservationsImportPort;
use App\Data\Integrations\IntegrationItemResult;
use App\Data\Integrations\IntegrationPage;
use App\Data\Integrations\IntegrationServiceIdentity;
use App\Exceptions\Integrations\PoisonIntegrationException;
use App\Exceptions\Integrations\RateLimitedIntegrationException;
use App\Jobs\ExecuteIntegrationRunJob;
use App\Jobs\ProcessIntegrationRunItemJob;
use App\Models\IntegrationConnection;
use App\Models\IntegrationConnectionCapability;
use App\Models\IntegrationDeadLetter;
use App\Models\IntegrationOperation;
use App\Models\IntegrationReconciliation;
use App\Models\IntegrationSyncCursor;
use App\Models\IntegrationSyncRun;
use App\Models\IntegrationSyncRunItem;
use App\Models\Property;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class IntegrationRunService
{
    public const CAPABILITIES = [
        'reservations.import',
        'accounting.journal_export',
        'webhook.outbound',
    ];

    public function __construct(private readonly CapabilityPortRegistry $ports) {}

    public function start(IntegrationConnection $connection, string $capability, ?string $propertyId, string $trigger, string $idempotencyKey, ?int $actorId): IntegrationSyncRun
    {
        $this->assertRunnable($connection, $capability, $propertyId);
        if (trim($idempotencyKey) === '') {
            throw new DomainException('A stable integration-run idempotency key is required.');
        }
        if (! in_array($trigger, ['manual', 'scheduled', 'webhook', 'reconciliation'], true)) {
            throw new DomainException('Unsupported integration run trigger.');
        }
        $cursor = IntegrationSyncCursor::query()->firstOrCreate([
            'integration_connection_id' => $connection->id,
            'property_id' => $propertyId,
            'capability' => $capability,
            'direction' => $this->direction($capability),
        ]);
        $run = IntegrationSyncRun::query()->firstOrCreate(
            ['integration_connection_id' => $connection->id, 'idempotency_key' => $idempotencyKey],
            [
                'property_id' => $propertyId,
                'requested_by' => $actorId,
                'capability' => $capability,
                'direction' => $this->direction($capability),
                'trigger' => $trigger,
                'status' => 'queued',
                'correlation_id' => (string) Str::uuid(),
                'starting_checkpoint' => $cursor->checkpoint,
            ],
        );
        if (! $run->wasRecentlyCreated && ($run->capability !== $capability || $run->property_id !== $propertyId || $run->trigger !== $trigger)) {
            throw new DomainException('This integration-run idempotency key was already used with different command facts.');
        }
        if ($run->wasRecentlyCreated) {
            DB::afterCommit(fn () => ExecuteIntegrationRunJob::dispatch($run->tenant_id, $run->id)->onQueue('integrations'));
        }

        return $run;
    }

    public function resume(IntegrationSyncRun $run, string $idempotencyKey, ?int $actorId, string $reason): IntegrationSyncRun
    {
        if (trim($idempotencyKey) === '') {
            throw new DomainException('A stable resume idempotency key is required.');
        }
        $idempotencyHash = hash('sha256', $idempotencyKey);
        $resumed = DB::transaction(function () use ($run, $idempotencyHash, $actorId, $reason): IntegrationSyncRun {
            $locked = IntegrationSyncRun::query()->lockForUpdate()->findOrFail($run->id);
            $connection = IntegrationConnection::query()->lockForUpdate()->findOrFail($locked->integration_connection_id);
            $existingOperation = IntegrationOperation::query()->where('integration_connection_id', $connection->id)
                ->where('operation', 'run_resumed')->where('idempotency_key_hash', $idempotencyHash)
                ->first();
            if ($existingOperation !== null) {
                if (data_get($existingOperation->safe_facts, 'run_id') === $locked->id) {
                    return $locked;
                }
                throw new DomainException('This resume idempotency key was already used for a different run.');
            }
            if ($locked->status !== 'blocked') {
                throw new DomainException('Only a specifically blocked integration run may be resumed.');
            }
            $this->assertRunnable($connection, $locked->capability, $locked->property_id);
            $locked->items()->where('status', 'blocked')->update([
                'status' => 'pending', 'available_at' => now(), 'started_at' => null, 'finished_at' => null, 'last_error' => null,
            ]);
            $locked->update([
                'status' => 'queued', 'trigger' => 'resume', 'claim_token' => null, 'claimed_at' => null,
                'lease_expires_at' => null, 'finished_at' => null, 'last_error' => null,
            ]);
            IntegrationOperationRecorder::record($connection, 'run_resumed', $actorId, $reason, [
                'run_id' => $locked->id, 'page_number' => $locked->page_number,
            ], $idempotencyHash);
            DB::afterCommit(fn () => ExecuteIntegrationRunJob::dispatch($locked->tenant_id, $locked->id)->onQueue('integrations'));

            return $locked->fresh();
        }, 3);

        return $resumed;
    }

    public function executePage(IntegrationSyncRun $run): void
    {
        $claimed = DB::transaction(function () use ($run): ?IntegrationSyncRun {
            $candidate = IntegrationSyncRun::query()->lockForUpdate()->findOrFail($run->id);
            if (in_array($candidate->status, ['completed', 'cancelled', 'failed'], true)) {
                return null;
            }
            if ($candidate->status === 'running' && $candidate->lease_expires_at?->isFuture()) {
                return null;
            }
            $connection = IntegrationConnection::query()->lockForUpdate()->findOrFail($candidate->integration_connection_id);
            if (! $connection->is_enabled || $connection->revoked_at !== null) {
                $candidate->update(['status' => 'blocked', 'last_error' => 'Connection disabled or revoked during run.']);

                return null;
            }
            $candidate->update([
                'status' => 'running',
                'claim_token' => Str::random(48),
                'claimed_at' => now(),
                'lease_expires_at' => now()->addMinutes(5),
                'started_at' => $candidate->started_at ?? now(),
                'attempt' => $candidate->attempt + 1,
            ]);

            return $candidate->fresh('connection');
        }, 3);
        if ($claimed === null) {
            return;
        }

        if ($claimed->page_in_progress) {
            $pendingIds = $claimed->items()->where('page_number', $claimed->page_number)
                ->whereIn('status', ['pending', 'retryable'])
                ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
                ->pluck('id');
            if ($pendingIds->isEmpty()) {
                $claimed->update(['claim_token' => null, 'lease_expires_at' => null]);
                $this->finalizePage($claimed);
            } else {
                $claimed->update(['claim_token' => null, 'lease_expires_at' => null]);
                foreach ($pendingIds as $itemId) {
                    ProcessIntegrationRunItemJob::dispatch($claimed->tenant_id, $itemId)->onQueue('integrations');
                }
            }

            return;
        }

        try {
            $cursor = IntegrationSyncCursor::query()->where('integration_connection_id', $claimed->integration_connection_id)
                ->where('property_id', $claimed->property_id)->where('capability', $claimed->capability)
                ->where('direction', $claimed->direction)->firstOrFail();
            $port = $this->ports->for($claimed->connection, $claimed->capability);
            $page = match ($claimed->capability) {
                'reservations.import' => $this->reservationPort($port)->fetchPage($claimed->connection, $cursor->checkpoint),
                'accounting.journal_export' => $this->accountingPort($port)->sourcePage($claimed->connection, $cursor->checkpoint),
                'webhook.outbound' => $this->webhookPort($port)->sourcePage($claimed->connection, $cursor->checkpoint),
                default => throw new RuntimeException('Unsupported capability.'),
            };
            $validatedItems = $this->validatedItems($page);
            $items = DB::transaction(function () use ($claimed, $page, $validatedItems): ?array {
                $locked = IntegrationSyncRun::query()->lockForUpdate()->findOrFail($claimed->id);
                $connection = IntegrationConnection::query()->lockForUpdate()->findOrFail($locked->integration_connection_id);
                if (! $connection->is_enabled || $connection->revoked_at !== null || $locked->status === 'blocked') {
                    $locked->update(['status' => 'blocked', 'last_error' => 'Connection disabled after page claim.', 'claim_token' => null, 'lease_expires_at' => null]);

                    return null;
                }
                $pageNumber = $locked->page_number + 1;
                $ids = [];
                foreach ($validatedItems as $fact) {
                    $item = IntegrationSyncRunItem::query()->firstOrCreate([
                        'integration_sync_run_id' => $locked->id,
                        'external_key' => $fact['external_key'],
                    ], [
                        'page_number' => $pageNumber,
                        'property_id' => $locked->property_id,
                        'payload_checksum' => $fact['checksum'],
                        'safe_payload' => SafeIntegrationError::value($fact['safe_snapshot'] ?? null),
                        'status' => 'pending',
                        'idempotency_key' => hash('sha256', $locked->tenant_id."\0".$locked->integration_connection_id."\0".$locked->capability."\0".$fact['external_key']),
                        'available_at' => now(),
                    ]);
                    if (! hash_equals($item->payload_checksum, $fact['checksum'])) {
                        throw new PoisonIntegrationException('A provider item identity changed payload within the same run.');
                    }
                    if ($item->page_number === $pageNumber && in_array($item->status, ['pending', 'retryable'], true)) {
                        $ids[] = $item->id;
                    }
                }
                $locked->update([
                    'page_number' => $pageNumber,
                    'pending_checkpoint' => $page->nextCheckpoint,
                    'pending_has_more' => $page->hasMore,
                    'page_in_progress' => true,
                    'item_count' => $locked->items()->count(),
                    'claim_token' => null,
                    'lease_expires_at' => null,
                ]);

                return $ids;
            }, 3);
            if ($items === null) {
                return;
            }
            if ($items === []) {
                $this->finalizePage($claimed->fresh());

                return;
            }
            foreach ($items as $itemId) {
                ProcessIntegrationRunItemJob::dispatch($claimed->tenant_id, $itemId)->onQueue('integrations');
            }
        } catch (RateLimitedIntegrationException $exception) {
            $claimed->update(['status' => 'queued', 'last_error' => SafeIntegrationError::from($exception), 'claim_token' => null, 'lease_expires_at' => null]);
            throw $exception;
        } catch (Throwable $exception) {
            $safe = SafeIntegrationError::from($exception);
            $claimed->update(['status' => 'failed', 'last_error' => $safe, 'finished_at' => now(), 'claim_token' => null, 'lease_expires_at' => null]);
            IntegrationReconciliation::query()->create([
                'integration_connection_id' => $claimed->integration_connection_id,
                'property_id' => $claimed->property_id,
                'kind' => 'run_page_failure',
                'status' => 'open',
                'reason_code' => $exception instanceof PoisonIntegrationException ? 'malformed_page' : 'page_fetch_failed',
                'safe_facts' => ['run_id' => $claimed->id, 'page_number' => $claimed->page_number + 1, 'error' => $safe],
            ]);
            throw $exception;
        }
    }

    public function processItem(IntegrationSyncRunItem $item): void
    {
        $claimed = DB::transaction(function () use ($item): ?IntegrationSyncRunItem {
            $candidate = IntegrationSyncRunItem::query()->lockForUpdate()->findOrFail($item->id);
            if (! in_array($candidate->status, ['pending', 'retryable'], true)) {
                return null;
            }
            $run = IntegrationSyncRun::query()->findOrFail($candidate->integration_sync_run_id);
            $connection = IntegrationConnection::query()->lockForUpdate()->findOrFail($run->integration_connection_id);
            if (! $connection->is_enabled || $connection->revoked_at !== null) {
                $run->update(['status' => 'blocked', 'last_error' => 'Connection disabled or revoked during run.']);

                return null;
            }
            $candidate->update(['status' => 'processing', 'attempt' => $candidate->attempt + 1, 'started_at' => now(), 'last_error' => null]);

            return $candidate->fresh('run.connection');
        }, 3);
        if ($claimed === null) {
            return;
        }
        $run = $claimed->run;
        $connection = $run->connection;
        $identity = new IntegrationServiceIdentity($run->tenant_id, $run->property_id, $connection->id, [$run->capability], $run->correlation_id);
        try {
            $port = $this->ports->for($connection, $run->capability);
            $result = match ($run->capability) {
                'reservations.import' => $this->reservationPort($port)->importReservation($connection, $claimed->external_key, $identity, $claimed->idempotency_key),
                'accounting.journal_export' => $this->accountingPort($port)->exportJournal($connection, $claimed->external_key, $identity, $claimed->idempotency_key),
                'webhook.outbound' => $this->webhookPort($port)->deliver($connection, $claimed->external_key, $identity, $claimed->idempotency_key),
                default => throw new RuntimeException('Unsupported capability.'),
            };
            $this->completeItem($claimed, $result);
        } catch (PoisonIntegrationException $exception) {
            $this->deadLetter($claimed, 'poison_item', $exception);
        } catch (RateLimitedIntegrationException $exception) {
            $claimed->update(['status' => 'retryable', 'last_error' => SafeIntegrationError::from($exception), 'available_at' => now()->addSeconds($exception->retryAfterSeconds)]);
            throw $exception;
        } catch (Throwable $exception) {
            $claimed->update(['status' => 'retryable', 'last_error' => SafeIntegrationError::from($exception), 'available_at' => now()->addMinute()]);
            throw $exception;
        }
    }

    public function deadLetter(IntegrationSyncRunItem $item, string $reasonCode, Throwable|string $error): IntegrationDeadLetter
    {
        $letter = DB::transaction(function () use ($item, $reasonCode, $error): IntegrationDeadLetter {
            $locked = IntegrationSyncRunItem::query()->lockForUpdate()->findOrFail($item->id);
            $run = IntegrationSyncRun::query()->findOrFail($locked->integration_sync_run_id);
            $locked->update(['status' => 'dead_letter', 'last_error' => SafeIntegrationError::from($error), 'finished_at' => now()]);

            return IntegrationDeadLetter::query()->updateOrCreate(
                ['integration_sync_run_item_id' => $locked->id],
                [
                    'integration_connection_id' => $run->integration_connection_id,
                    'property_id' => $run->property_id,
                    'reason_code' => $reasonCode,
                    'safe_error' => SafeIntegrationError::from($error),
                    'status' => 'open',
                    'resolved_at' => null,
                    'resolution' => null,
                ],
            );
        }, 3);
        $this->finalizePage($item->run()->firstOrFail());

        return $letter;
    }

    public function replay(IntegrationDeadLetter $letter, ?int $actorId, string $reason): IntegrationDeadLetter
    {
        DB::transaction(function () use ($letter, $actorId, $reason): void {
            $locked = IntegrationDeadLetter::query()->lockForUpdate()->findOrFail($letter->id);
            $item = $locked->item()->lockForUpdate()->first();
            $run = $item === null ? null : IntegrationSyncRun::query()->lockForUpdate()->findOrFail($item->integration_sync_run_id);
            if ($item === null || $run?->page_in_progress || $locked->status === 'replaying' || in_array($item->status, ['pending', 'processing', 'retryable'], true)) {
                throw new DomainException('The original item is still active or unavailable.');
            }
            $item->update(['status' => 'pending', 'available_at' => now(), 'finished_at' => null, 'last_error' => null]);
            $locked->update(['status' => 'replaying', 'replay_count' => $locked->replay_count + 1, 'last_replayed_at' => now(), 'owner_id' => $actorId]);
            IntegrationOperationRecorder::record($locked->connection, 'dead_letter_replayed', $actorId, $reason, ['dead_letter_id' => $locked->id]);
            DB::afterCommit(fn () => ProcessIntegrationRunItemJob::dispatch($locked->tenant_id, $locked->integration_sync_run_item_id)->onQueue('integrations'));
        }, 3);

        return $letter->fresh();
    }

    public function finalizePage(IntegrationSyncRun $run): void
    {
        $dispatchNext = DB::transaction(function () use ($run): bool {
            $locked = IntegrationSyncRun::query()->lockForUpdate()->findOrFail($run->id);
            $connection = IntegrationConnection::query()->lockForUpdate()->findOrFail($locked->integration_connection_id);
            if (! $connection->is_enabled || $connection->revoked_at !== null || $locked->status === 'blocked') {
                $locked->update([
                    'status' => 'blocked', 'last_error' => 'Connection disabled before cursor commit.',
                    'claim_token' => null, 'lease_expires_at' => null,
                ]);
                $locked->items()->whereIn('status', ['pending', 'processing', 'retryable'])->update([
                    'status' => 'blocked', 'last_error' => 'Connection disabled before cursor commit.',
                ]);

                return false;
            }
            $items = $locked->items()->where('page_number', $locked->page_number)->lockForUpdate()->get();
            if ($items->contains(fn ($item) => in_array($item->status, ['pending', 'processing', 'retryable'], true))) {
                return false;
            }
            $cursor = IntegrationSyncCursor::query()->where('integration_connection_id', $locked->integration_connection_id)
                ->where('property_id', $locked->property_id)->where('capability', $locked->capability)->where('direction', $locked->direction)
                ->lockForUpdate()->firstOrFail();
            $cursor->update(['checkpoint' => $locked->pending_checkpoint, 'version' => $cursor->version + 1, 'committed_at' => now()]);
            $successes = $locked->items()->where('status', 'succeeded')->count();
            $dead = $locked->items()->where('status', 'dead_letter')->count();
            $next = $locked->pending_has_more;
            $locked->update([
                'status' => $next ? 'queued' : 'completed',
                'pending_checkpoint' => null,
                'pending_has_more' => false,
                'page_in_progress' => false,
                'success_count' => $successes,
                'error_count' => $dead,
                'dead_letter_count' => $dead,
                'finished_at' => $next ? null : now(),
            ]);
            if (! $next) {
                $connection->update([
                    'last_synced_at' => now(), 'last_success_at' => now(), 'last_error' => null, 'health_status' => $dead === 0 ? 'healthy' : 'degraded',
                ]);
            }

            return $next;
        }, 3);
        if ($dispatchNext) {
            ExecuteIntegrationRunJob::dispatch($run->tenant_id, $run->id)->onQueue('integrations');
        }
    }

    private function completeItem(IntegrationSyncRunItem $item, IntegrationItemResult $result): void
    {
        $run = DB::transaction(function () use ($item, $result): IntegrationSyncRun {
            $locked = IntegrationSyncRunItem::query()->lockForUpdate()->findOrFail($item->id);
            $run = IntegrationSyncRun::query()->lockForUpdate()->findOrFail($locked->integration_sync_run_id);
            $connection = IntegrationConnection::query()->lockForUpdate()->findOrFail($run->integration_connection_id);
            if (! $connection->is_enabled || $connection->revoked_at !== null || $run->status === 'blocked' || $locked->status === 'blocked') {
                $locked->update(['status' => 'blocked', 'last_error' => 'Connection disabled after item claim.']);
                $run->update(['status' => 'blocked', 'last_error' => 'Connection disabled after item claim.']);

                return $run->fresh();
            }
            $locked->update([
                'status' => 'succeeded', 'local_key' => $result->localKey, 'http_status' => $result->httpStatus,
                'latency_ms' => $result->latencyMs, 'request_checksum' => $result->requestChecksum,
                'response_checksum' => $result->responseChecksum, 'finished_at' => now(), 'last_error' => null,
            ]);

            return $run->fresh();
        }, 3);
        if ($run->status === 'blocked') {
            return;
        }
        if ($run->status === 'completed') {
            IntegrationDeadLetter::query()->where('integration_sync_run_item_id', $item->id)->update([
                'status' => 'resolved', 'resolved_at' => now(), 'resolution' => 'Replay succeeded.',
            ]);
            $run->update([
                'success_count' => $run->items()->where('status', 'succeeded')->count(),
                'error_count' => $run->items()->where('status', 'dead_letter')->count(),
                'dead_letter_count' => $run->items()->where('status', 'dead_letter')->count(),
            ]);

            return;
        }
        $this->finalizePage($run);
    }

    /** @return list<array{external_key:string,checksum:string,safe_snapshot?:array<string,mixed>}> */
    private function validatedItems(IntegrationPage $page): array
    {
        $keys = [];
        $validated = [];
        foreach ($page->items as $item) {
            $externalKey = $item['external_key'] ?? null;
            $checksum = $item['checksum'] ?? null;
            $safeSnapshot = $item['safe_snapshot'] ?? null;
            if (! is_string($externalKey) || trim($externalKey) === ''
                || ! is_string($checksum) || preg_match('/^[a-f0-9]{64}$/', $checksum) !== 1
                || ($safeSnapshot !== null && ! is_array($safeSnapshot))
                || isset($keys[$externalKey])) {
                throw new PoisonIntegrationException('The provider page is malformed or contains duplicate item identities.');
            }
            $keys[$externalKey] = true;
            $fact = [
                'external_key' => $externalKey,
                'checksum' => $checksum,
            ];
            if ($safeSnapshot !== null) {
                $fact['safe_snapshot'] = $safeSnapshot;
            }
            $validated[] = $fact;
        }
        if ($page->hasMore && $page->nextCheckpoint === null) {
            throw new PoisonIntegrationException('A partial provider page omitted its continuation checkpoint.');
        }

        return $validated;
    }

    private function assertRunnable(IntegrationConnection $connection, string $capability, ?string $propertyId): void
    {
        if (! in_array($capability, self::CAPABILITIES, true) || ! in_array($capability, $connection->capabilities ?? [], true)) {
            throw new DomainException('The connection does not grant this capability.');
        }
        if (! $connection->is_enabled || $connection->revoked_at !== null) {
            throw new DomainException('The connection is disabled or revoked.');
        }
        if ($connection->property_id !== null && $propertyId !== $connection->property_id) {
            throw new DomainException('The run property does not match the connection scope.');
        }
        if ($propertyId !== null && ! Property::query()->whereKey($propertyId)->exists()) {
            throw new DomainException('The run property does not exist in this tenant.');
        }
        if (! IntegrationConnectionCapability::query()->where('integration_connection_id', $connection->id)
            ->where('capability', $capability)->where('direction', $this->direction($capability))->where('state', 'enabled')->exists()) {
            throw new DomainException('The connection capability is not enabled.');
        }
    }

    private function direction(string $capability): string
    {
        return $capability === 'reservations.import' ? 'inbound' : 'outbound';
    }

    private function reservationPort(object $port): ReservationsImportPort
    {
        return $port instanceof ReservationsImportPort ? $port : throw new RuntimeException('Registered port does not implement ReservationsImportPort.');
    }

    private function accountingPort(object $port): AccountingJournalExportPort
    {
        return $port instanceof AccountingJournalExportPort ? $port : throw new RuntimeException('Registered port does not implement AccountingJournalExportPort.');
    }

    private function webhookPort(object $port): OutboundWebhookPort
    {
        return $port instanceof OutboundWebhookPort ? $port : throw new RuntimeException('Registered port does not implement OutboundWebhookPort.');
    }
}
