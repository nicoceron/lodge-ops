<?php

namespace App\Services;

use App\Mail\CommunicationMail;
use App\Models\Communication;
use App\Models\DeliveryAttempt;
use App\Models\GeneratedDocument;
use App\Models\Guest;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class CommunicationDeliveryService
{
    public function __construct(private readonly MessageTemplateService $templates) {}

    public function deliver(Communication $communication): void
    {
        $prepared = DB::transaction(function () use ($communication): ?array {
            $locked = Communication::query()
                ->with('guest')
                ->whereKey($communication->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->sent_at !== null || $locked->status === 'sent') {
                return null;
            }
            if ($locked->channel !== 'email') {
                throw new DomainException("No delivery adapter is configured for channel [{$locked->channel}].");
            }

            $recipient = data_get($locked->metadata, 'recipient', $locked->guest?->email);
            if (! is_string($recipient) || trim($recipient) === '') {
                throw new DomainException('The communication recipient has no email address.');
            }
            $guest = $locked->guest;
            if ($guest instanceof Guest && $this->templates->isSuppressed($guest, $locked->channel)) {
                $locked->forceFill([
                    'status' => 'suppressed',
                    'metadata' => [
                        ...($locked->metadata ?? []),
                        'suppressed_at' => now()->toIso8601String(),
                    ],
                ])->save();

                return null;
            }

            $lastAttempt = DeliveryAttempt::query()
                ->where('communication_id', $locked->id)
                ->orderByDesc('attempt')
                ->lockForUpdate()
                ->first();
            $attemptNumber = ((int) ($lastAttempt?->attempt ?? 0)) + 1;
            $attempt = DeliveryAttempt::query()->create([
                'communication_id' => $locked->id,
                'provider' => config('mail.default', 'smtp'),
                'status' => 'sending',
                'idempotency_key' => "communication:{$locked->id}",
                'attempt' => $attemptNumber,
                'attempted_at' => now(),
            ]);
            $locked->forceFill(['status' => 'sending'])->save();

            return [
                'communication_id' => $locked->id,
                'attempt_id' => $attempt->id,
                'recipient' => trim($recipient),
                'subject' => $locked->subject ?: config('app.name').' update',
                'body' => $locked->body,
                'document' => ($documentId = data_get($locked->metadata, 'generated_document_id'))
                    ? GeneratedDocument::query()->whereKey($documentId)->first()
                    : null,
            ];
        }, 3);

        if ($prepared === null) {
            return;
        }

        try {
            Mail::to($prepared['recipient'])->send(new CommunicationMail(
                $prepared['subject'],
                $prepared['body'],
                $prepared['document']?->storage_disk,
                $prepared['document']?->storage_path,
                $prepared['document']?->file_name,
            ));

            DB::transaction(function () use ($prepared): void {
                DeliveryAttempt::query()->whereKey($prepared['attempt_id'])->update([
                    'status' => 'sent',
                    'response' => 'Accepted by the configured Laravel mail transport.',
                ]);
                Communication::query()->whereKey($prepared['communication_id'])->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                    'delivered_at' => now(),
                ]);
            });
        } catch (Throwable $exception) {
            DB::transaction(function () use ($prepared, $exception): void {
                DeliveryAttempt::query()->whereKey($prepared['attempt_id'])->update([
                    'status' => 'failed',
                    'response' => mb_substr($exception->getMessage(), 0, 5000),
                ]);
                Communication::query()->whereKey($prepared['communication_id'])->update(['status' => 'failed']);
            });

            throw $exception;
        }
    }
}
