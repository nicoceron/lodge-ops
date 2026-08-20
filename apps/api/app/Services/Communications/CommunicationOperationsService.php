<?php

namespace App\Services\Communications;

use App\Models\Communication;
use App\Models\CommunicationSuppression;
use App\Models\Property;
use App\Models\User;
use App\Services\Automation\OutboxRecorder;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CommunicationOperationsService
{
    public function __construct(private readonly OutboxRecorder $outbox) {}

    public function retry(User $actor, Communication $communication): Communication
    {
        if (! in_array($communication->status, ['failed', 'retry_pending', 'outcome_uncertain'], true)) {
            throw new DomainException('Only failed or uncertain deliveries may be retried.');
        }

        return DB::transaction(function () use ($actor, $communication): Communication {
            $locked = Communication::query()->whereKey($communication->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill([
                'status' => 'queued',
                'failed_at' => null,
                'metadata' => [
                    ...($locked->metadata ?? []),
                    'last_retry_requested_by' => $actor->id,
                    'last_retry_requested_at' => now()->toIso8601String(),
                ],
            ])->save();
            $this->outbox->record('communication', $locked->id, 'communication.queued', [
                'communication_id' => $locked->id,
                'channel' => $locked->channel,
                'operation' => 'retry_same_delivery',
                'actor_id' => $actor->id,
            ]);

            return $locked;
        }, 3);
    }

    public function newResend(User $actor, Communication $original): Communication
    {
        return DB::transaction(function () use ($actor, $original): Communication {
            $copy = Communication::query()->create([
                'property_id' => $original->property_id,
                'guest_id' => $original->guest_id,
                'reservation_id' => $original->reservation_id,
                'channel' => $original->channel,
                'direction' => 'outbound',
                'purpose' => $original->purpose,
                'template_key' => $original->template_key,
                'template_version' => $original->template_version,
                'locale' => $original->locale,
                'status' => 'queued',
                'subject' => $original->subject,
                'body' => $original->body,
                'content_checksum' => $original->content_checksum,
                'automation_key' => 'manual-resend:'.$original->id.':'.Str::uuid(),
                'metadata' => [
                    ...($original->metadata ?? []),
                    'resend_of_communication_id' => $original->id,
                    'resend_requested_by' => $actor->id,
                    'resend_requested_at' => now()->toIso8601String(),
                ],
            ]);
            $this->outbox->record('communication', $copy->id, 'communication.queued', [
                'communication_id' => $copy->id,
                'channel' => $copy->channel,
                'operation' => 'new_resend',
                'actor_id' => $actor->id,
            ]);

            return $copy;
        }, 3);
    }

    public function testSend(User $actor, Property $property, string $recipient, string $subject, string $body): Communication
    {
        return DB::transaction(function () use ($actor, $property, $recipient, $subject, $body): Communication {
            $communication = Communication::query()->create([
                'property_id' => $property->id,
                'channel' => 'email',
                'direction' => 'outbound',
                'purpose' => 'transactional',
                'status' => 'queued',
                'subject' => '[TEST] '.trim($subject),
                'body' => "TEST MESSAGE — NOT A GUEST COMMUNICATION\n\n".$body,
                'content_checksum' => hash('sha256', '[TEST] '.trim($subject)."\n".$body),
                'automation_key' => 'test-send:'.Str::uuid(),
                'metadata' => [
                    'recipient' => mb_strtolower(trim($recipient)),
                    'is_test' => true,
                    'test_requested_by' => $actor->id,
                    'test_requested_at' => now()->toIso8601String(),
                ],
            ]);
            $this->outbox->record('communication', $communication->id, 'communication.queued', [
                'communication_id' => $communication->id,
                'channel' => 'email',
                'operation' => 'test_send',
                'actor_id' => $actor->id,
            ]);

            return $communication;
        }, 3);
    }

    public function unsuppress(User $actor, CommunicationSuppression $suppression, string $reason): void
    {
        if (trim($reason) === '') {
            throw new DomainException('Unsuppression requires an explicit reason.');
        }
        $suppression->forceFill([
            'lifted_at' => now(),
            'lifted_by' => $actor->id,
            'lift_reason' => mb_substr(trim($reason), 0, 1000),
        ])->save();
    }
}
