<?php

namespace App\Services;

use App\Data\CommunicationProviderRequest;
use App\Exceptions\CommunicationProviderException;
use App\Models\Communication;
use App\Models\DeliveryAttempt;
use App\Models\GeneratedDocument;
use App\Models\Guest;
use App\Models\Membership;
use App\Models\User;
use App\Services\Communications\CommunicationConsentService;
use App\Services\Communications\CommunicationIdempotencyWindow;
use App\Services\Communications\CommunicationProviderResolver;
use App\Services\Communications\CommunicationPurposePolicyService;
use App\Services\Documents\DocumentArtifactStore;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

class CommunicationDeliveryService
{
    public function __construct(
        private readonly MessageTemplateService $templates,
        private readonly CommunicationConsentService $consent,
        private readonly CommunicationProviderResolver $providers,
        private readonly DocumentArtifactStore $documents,
        private readonly CommunicationPurposePolicyService $purposePolicies,
        private readonly CommunicationIdempotencyWindow $idempotencyWindow,
        private readonly TenantContext $tenantContext,
    ) {}

    public function deliver(Communication $communication): void
    {
        $prepared = DB::transaction(function () use ($communication): ?array {
            $locked = Communication::query()->with(['guest', 'reservation'])
                ->whereKey($communication->id)->lockForUpdate()->firstOrFail();

            if ($locked->delivered_at !== null || in_array($locked->status, ['delivered', 'hard_bounced', 'complained', 'suppressed'], true)) {
                return null;
            }
            if ($locked->channel !== 'email') {
                throw new DomainException("No delivery adapter is configured for channel [{$locked->channel}].");
            }
            $this->purposePolicies->approved($locked->purpose ?: 'transactional');

            $recipient = data_get($locked->metadata, 'recipient', $locked->guest?->email);
            if (! is_string($recipient) || trim($recipient) === '') {
                throw new DomainException('The communication recipient has no email address.');
            }
            $recipient = mb_strtolower(trim($recipient));
            $propertyId = $locked->property_id ?? $locked->reservation?->property_id;
            $guest = $locked->guest;
            $suppressed = $this->templates->isRecipientSuppressed($recipient, $locked->channel, $propertyId);
            $consentDenied = $guest instanceof Guest && ! $this->consent->allows($locked, $guest, $propertyId);
            if ($suppressed || $consentDenied) {
                $locked->forceFill([
                    'status' => 'suppressed',
                    'metadata' => [
                        ...($locked->metadata ?? []),
                        'suppressed_at' => now()->toIso8601String(),
                        'recipient_hash' => $this->templates->recipientHash($recipient),
                    ],
                ])->save();

                return null;
            }

            $attempts = DeliveryAttempt::query()
                ->where('communication_id', $locked->id)
                ->where('idempotency_key', "communication:{$locked->id}")
                ->orderBy('attempt')->lockForUpdate()->get();
            $existing = $attempts->last();

            if ($this->idempotencyWindow->hasAuthoritativeOutcome($attempts)) {
                return null;
            }
            if ($existing !== null && $existing->status === 'sending' && $existing->attempted_at->isAfter(now()->subMinute())) {
                return null;
            }
            $now = now()->toImmutable();
            $idempotencyExpiresAt = $this->idempotencyWindow->anchor($locked, $attempts, $now);
            if ($this->idempotencyWindow->requiresReconciliation($attempts, $idempotencyExpiresAt, $now)) {
                $this->idempotencyWindow->markReconciliationRequired($locked, $attempts, $idempotencyExpiresAt);

                return null;
            }

            $resolved = $this->providers->resolve($locked);
            $attempt = DeliveryAttempt::query()->create([
                'communication_id' => $locked->id,
                'communication_provider_connection_id' => $resolved->connection?->id,
                'provider' => $resolved->provider->name(),
                'provider_account_id' => $resolved->accountId,
                'status' => 'sending',
                'kind' => $existing === null ? 'initial' : 'retry',
                'idempotency_key' => "communication:{$locked->id}",
                'request_checksum' => $this->requestChecksum($locked, $recipient, $resolved->from),
                'attempt' => ((int) ($existing?->attempt ?? 0)) + 1,
                'retry_state' => $existing === null ? 'none' : 'retrying',
                'attempted_at' => now(),
            ]);
            $locked->forceFill([
                'property_id' => $propertyId,
                'status' => 'sending',
                'content_checksum' => $locked->content_checksum ?: hash('sha256', ($locked->subject ?? '')."\n".$locked->body),
            ])->save();

            $attachment = $this->attachment($locked);

            return [
                'communication_id' => $locked->id,
                'attempt_id' => $attempt->id,
                'idempotency_expires_at' => $idempotencyExpiresAt,
                'provider' => $resolved->provider,
                'is_local' => $resolved->provider->name() === 'laravel-mail',
                'request' => new CommunicationProviderRequest(
                    $attempt->idempotency_key,
                    $resolved->apiKey,
                    $resolved->from,
                    $resolved->replyTo,
                    $recipient,
                    $locked->subject ?: config('app.name').' update',
                    $locked->body,
                    '<div>'.nl2br(e($locked->body)).'</div>',
                    $attachment === null ? [] : [$attachment],
                ),
            ];
        }, 3);

        if ($prepared === null) {
            return;
        }

        try {
            $result = $prepared['provider']->send($prepared['request']);

            DB::transaction(function () use ($prepared, $result): void {
                $now = now();
                DeliveryAttempt::query()->whereKey($prepared['attempt_id'])->update([
                    'status' => $prepared['is_local'] ? 'sent' : 'provider_accepted',
                    'provider_reference' => $result->providerMessageId,
                    'provider_message_id' => $result->providerMessageId,
                    'response' => 'Provider accepted the message.',
                    'accepted_at' => $now,
                    'sent_at' => $prepared['is_local'] ? $now : null,
                    'retry_state' => 'none',
                    'safe_error' => null,
                ]);
                Communication::query()->whereKey($prepared['communication_id'])->update([
                    'status' => $prepared['is_local'] ? 'sent' : 'provider_accepted',
                    'accepted_at' => $now,
                    'sent_at' => $prepared['is_local'] ? $now : null,
                ]);
            });
        } catch (CommunicationProviderException $exception) {
            $this->recordFailure($prepared, $exception);
            throw $exception;
        } catch (Throwable $exception) {
            $safe = new CommunicationProviderException('Unexpected provider failure.', 'unexpected_provider_error', true, true);
            $this->recordFailure($prepared, $safe);
            throw $exception;
        }
    }

    /** @param array<string, mixed> $prepared */
    private function recordFailure(array $prepared, CommunicationProviderException $exception): void
    {
        DB::transaction(function () use ($prepared, $exception): void {
            $expiredUncertainty = $exception->outcomeUncertain
                && now()->greaterThanOrEqualTo($prepared['idempotency_expires_at']);
            $status = $expiredUncertainty
                ? 'reconciliation_required'
                : ($exception->outcomeUncertain ? 'outcome_uncertain' : ($exception->retryable ? 'retry_pending' : 'failed'));
            DeliveryAttempt::query()->whereKey($prepared['attempt_id'])->update([
                'status' => $status,
                'retry_state' => $status,
                'error_code' => $exception->safeCode,
                'safe_error' => mb_substr($exception->getMessage(), 0, 500),
                'failed_at' => $exception->outcomeUncertain ? null : now(),
                'reconcile_after' => $exception->outcomeUncertain ? $prepared['idempotency_expires_at'] : null,
            ]);
            Communication::query()->whereKey($prepared['communication_id'])->update([
                'status' => $status,
                'failed_at' => $exception->outcomeUncertain ? null : now(),
            ]);
        });
    }

    /** @return array{filename:string,content:string,content_type:string}|null */
    private function attachment(Communication $communication): ?array
    {
        $documentId = data_get($communication->metadata, 'generated_document_id');
        if (! is_string($documentId) || $documentId === '') {
            return null;
        }

        $document = GeneratedDocument::query()->whereKey($documentId)->firstOrFail();
        $queuedBy = data_get($communication->metadata, 'queued_by');
        $actor = is_numeric($queuedBy) ? User::query()->find((int) $queuedBy) : null;
        $systemReceipt = data_get($communication->metadata, 'system_generated_receipt') === true;
        $document->loadMissing('generationRequest');
        $authorizedSystemReceipt = $systemReceipt
            && in_array($document->kind, ['payment_receipt', 'refund_receipt'], true)
            && $document->generationRequest?->requested_by === null;
        if (! $authorizedSystemReceipt && ($actor === null || ! $this->canEmailDocument($actor, $document))) {
            throw new DomainException('The document email is no longer authorized for delivery.');
        }
        if ($document->purged_at !== null || ($document->expires_at !== null && $document->expires_at->isPast())) {
            throw new DomainException('The communication attachment is unavailable or expired.');
        }
        if ($communication->reservation_id !== null && $document->reservation_id !== $communication->reservation_id) {
            throw new DomainException('The communication attachment is outside the reservation scope.');
        }

        $bytes = $this->documents->verifiedBytes($document->storage_disk, $document->storage_path, $document->checksum);

        return ['filename' => $document->file_name, 'content' => base64_encode($bytes), 'content_type' => $document->mime_type];
    }

    private function canEmailDocument(User $actor, GeneratedDocument $document): bool
    {
        if (! $this->tenantContext->check() || $this->tenantContext->id() !== $document->tenant_id) {
            return false;
        }

        $propertyId = $document->reservation?->property_id;
        $membership = Membership::withoutGlobalScopes()
            ->where('tenant_id', $document->tenant_id)
            ->where('user_id', $actor->id)
            ->where('is_active', true)
            ->when(
                $propertyId,
                fn ($query) => $query->where(fn ($scope) => $scope->whereNull('property_id')->orWhere('property_id', $propertyId)),
                fn ($query) => $query->whereNull('property_id'),
            )
            ->orderByRaw('property_id is null')
            ->first();
        if ($membership === null) {
            return false;
        }

        $tenant = $this->tenantContext->tenant();
        $previousMembership = $this->tenantContext->membership();
        $this->tenantContext->set($tenant, $membership);

        try {
            return $actor->can('email', $document);
        } finally {
            $this->tenantContext->set($tenant, $previousMembership);
        }
    }

    private function requestChecksum(Communication $communication, string $recipient, string $from): string
    {
        return hash('sha256', json_encode([
            'communication_id' => $communication->id,
            'recipient_hash' => $this->templates->recipientHash($recipient),
            'from' => $from,
            'subject' => $communication->subject,
            'body_checksum' => hash('sha256', $communication->body),
        ], JSON_THROW_ON_ERROR));
    }
}
