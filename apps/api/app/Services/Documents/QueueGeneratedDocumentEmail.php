<?php

namespace App\Services\Documents;

use App\Enums\CommunicationPurpose;
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

        return $this->queue($actor, $document, $idempotencyKey, $recipient, false);
    }

    public function handleSystemReceipt(GeneratedDocument $document): ?Communication
    {
        $document->loadMissing(['generationRequest', 'guest', 'reservation']);
        if (! in_array($document->kind, ['payment_receipt', 'refund_receipt'], true)
            || $document->generationRequest?->requested_by !== null) {
            throw new DomainException('Only system-generated payment and refund receipts may use automatic delivery.');
        }
        if (! is_string($document->guest?->email) || trim($document->guest->email) === '') {
            return null;
        }

        return $this->queue(null, $document, 'system-receipt:'.$document->id, null, true);
    }

    private function queue(?User $actor, GeneratedDocument $document, string $idempotencyKey, ?string $recipient, bool $systemReceipt): Communication
    {
        if ($document->expires_at?->isPast() || $document->purged_at !== null) {
            throw new DomainException('Expired or purged documents cannot be emailed.');
        }
        $this->artifacts->verifiedBytes($document->storage_disk, $document->storage_path, $document->checksum);
        $document->loadMissing(['guest', 'reservation']);
        $recipient ??= $document->guest?->email;
        if (! is_string($recipient) || trim($recipient) === '') {
            throw new DomainException('The document recipient has no email address.');
        }

        return DB::transaction(function () use ($actor, $document, $idempotencyKey, $recipient, $systemReceipt): Communication {
            $key = hash('sha256', $document->id.'|'.mb_strtolower(trim($recipient)).'|'.$idempotencyKey);
            $existing = Communication::query()->where('metadata->document_email_key', $key)->first();
            if ($existing !== null) {
                return $existing;
            }
            $communication = Communication::query()->create([
                'property_id' => $document->reservation?->property_id,
                'guest_id' => $document->guest_id,
                'reservation_id' => $document->reservation_id,
                'channel' => 'email',
                'direction' => 'outbound',
                'purpose' => match ($document->kind) {
                    'payment_receipt' => CommunicationPurpose::PaymentReceipt->value,
                    'refund_receipt' => CommunicationPurpose::RefundReceipt->value,
                    'folio_statement' => CommunicationPurpose::CheckoutFolio->value,
                    default => CommunicationPurpose::Transactional->value,
                },
                'status' => 'queued',
                'subject' => 'Your '.$document->kind,
                'body' => 'Your requested stay document is attached.',
                'metadata' => [
                    'generated_document_id' => $document->id,
                    'document_email_key' => $key,
                    'queued_by' => $actor?->id,
                    'system_generated_receipt' => $systemReceipt,
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
