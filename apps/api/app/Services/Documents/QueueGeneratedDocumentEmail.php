<?php

namespace App\Services\Documents;

use App\Models\Communication;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Services\Automation\OutboxRecorder;
use DomainException;
use Illuminate\Support\Facades\DB;

final class QueueGeneratedDocumentEmail
{
    public function __construct(private readonly DocumentArtifactStore $artifacts, private readonly OutboxRecorder $outbox) {}

    public function handle(User $actor, GeneratedDocument $document, string $idempotencyKey, ?string $recipient = null): Communication
    {
        $actor->can('email', $document) || abort(403);
        if ($document->expires_at?->isPast() || $document->purged_at !== null) {
            throw new DomainException('Expired or purged documents cannot be emailed.');
        }
        $this->artifacts->verifiedBytes($document->storage_disk, $document->storage_path, $document->checksum);
        $document->loadMissing(['guest', 'reservation']);
        $recipient ??= $document->guest?->email;
        if (! is_string($recipient) || trim($recipient) === '') {
            throw new DomainException('The document recipient has no email address.');
        }

        return DB::transaction(function () use ($actor, $document, $idempotencyKey, $recipient): Communication {
            $key = hash('sha256', $document->id.'|'.mb_strtolower(trim($recipient)).'|'.$idempotencyKey);
            $existing = Communication::query()->where('metadata->document_email_key', $key)->first();
            if ($existing !== null) {
                return $existing;
            }
            $communication = Communication::query()->create([
                'guest_id' => $document->guest_id,
                'reservation_id' => $document->reservation_id,
                'channel' => 'email',
                'direction' => 'outbound',
                'status' => 'queued',
                'subject' => 'Your '.$document->kind,
                'body' => 'Your requested stay document is attached.',
                'metadata' => [
                    'generated_document_id' => $document->id,
                    'document_email_key' => $key,
                    'queued_by' => $actor->id,
                    'recipient' => trim($recipient),
                ],
            ]);
            $this->outbox->record('communication', $communication->id, 'communication.queued', [
                'communication_id' => $communication->id, 'channel' => 'email',
            ]);

            return $communication;
        }, 3);
    }
}
