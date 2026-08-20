<?php

namespace App\Services;

use App\Data\CommunicationProviderRequest;
use App\Exceptions\CommunicationProviderException;
use App\Models\Communication;
use App\Models\DeliveryAttempt;
use App\Models\GeneratedDocument;
use App\Models\Guest;
use App\Services\Communications\CommunicationConsentService;
use App\Services\Communications\CommunicationProviderResolver;
use App\Services\Documents\DocumentArtifactStore;
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

            $recipient = data_get($locked->metadata, 'recipient', $locked->guest?->email);
            if (! is_string($recipient) || trim($recipient) === '') {
                throw new DomainException('The communication recipient has no email address.');
            }
            $recipient = mb_strtolower(trim($recipient));
            $propertyId = $locked->property_id ?? $locked->reservation?->property_id;
            $guest = $locked->guest;
            if ($guest instanceof Guest && (! $this->consent->allows($locked, $guest, $propertyId)
                || $this->templates->isSuppressed($guest, $locked->channel, $propertyId))) {
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

            $existing = DeliveryAttempt::query()
                ->where('communication_id', $locked->id)
                ->where('idempotency_key', "communication:{$locked->id}")
                ->orderByDesc('attempt')->lockForUpdate()->first();

            if ($existing !== null && in_array($existing->status, ['provider_accepted', 'sent', 'delivered'], true)) {
                return null;
            }
            if ($existing !== null && $existing->status === 'sending' && $existing->attempted_at->isAfter(now()->subMinute())) {
                return null;
            }
            if ($existing !== null && $existing->status === 'sending'
                && $existing->attempted_at->isBefore(now()->subHours((int) config('communications.provider.idempotency_window_hours', 24)))) {
                $existing->forceFill([
                    'status' => 'reconciliation_required',
                    'retry_state' => 'reconciliation_required',
                    'safe_error' => 'Provider outcome remained uncertain beyond the idempotency window.',
                    'reconcile_after' => now(),
                ])->save();
                $locked->forceFill(['status' => 'reconciliation_required'])->save();

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
            $status = $exception->outcomeUncertain ? 'outcome_uncertain' : ($exception->retryable ? 'retry_pending' : 'failed');
            DeliveryAttempt::query()->whereKey($prepared['attempt_id'])->update([
                'status' => $status,
                'retry_state' => $status,
                'error_code' => $exception->safeCode,
                'safe_error' => mb_substr($exception->getMessage(), 0, 500),
                'failed_at' => $exception->outcomeUncertain ? null : now(),
                'reconcile_after' => $exception->outcomeUncertain ? now()->addHours(24) : null,
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
        if ($document->purged_at !== null || ($document->expires_at !== null && $document->expires_at->isPast())) {
            throw new DomainException('The communication attachment is unavailable or expired.');
        }
        if ($communication->reservation_id !== null && $document->reservation_id !== $communication->reservation_id) {
            throw new DomainException('The communication attachment is outside the reservation scope.');
        }

        $bytes = $this->documents->verifiedBytes($document->storage_disk, $document->storage_path, $document->checksum);

        return ['filename' => $document->file_name, 'content' => base64_encode($bytes), 'content_type' => $document->mime_type];
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
