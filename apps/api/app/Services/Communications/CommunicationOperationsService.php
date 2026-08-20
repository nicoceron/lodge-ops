<?php

namespace App\Services\Communications;

use App\Models\Communication;
use App\Models\CommunicationSuppression;
use App\Models\DeliveryAttempt;
use App\Models\Property;
use App\Models\User;
use App\Services\Automation\OutboxRecorder;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CommunicationOperationsService
{
    public function __construct(
        private readonly OutboxRecorder $outbox,
        private readonly CommunicationIdempotencyWindow $idempotencyWindow,
    ) {}

    public function retry(User $actor, Communication $communication): Communication
    {
        $actor->can('retry', $communication) || abort(403);
        if (! in_array($communication->status, ['failed', 'retry_pending', 'outcome_uncertain'], true)) {
            throw new DomainException('Only failed or uncertain deliveries may be retried.');
        }

        $result = DB::transaction(function () use ($actor, $communication): array {
            $locked = Communication::query()->whereKey($communication->id)->lockForUpdate()->firstOrFail();
            $attempts = DeliveryAttempt::query()->where('communication_id', $locked->id)
                ->where('idempotency_key', 'communication:'.$locked->id)
                ->orderBy('attempt')->lockForUpdate()->get();
            $now = now()->toImmutable();
            $expiresAt = $this->idempotencyWindow->anchor($locked, $attempts, $now);
            if ($this->idempotencyWindow->requiresReconciliation($attempts, $expiresAt, $now)) {
                $this->idempotencyWindow->markReconciliationRequired($locked, $attempts, $expiresAt);

                return ['communication' => $locked, 'reconciliation_required' => true];
            }
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

            return ['communication' => $locked, 'reconciliation_required' => false];
        }, 3);
        if ($result['reconciliation_required']) {
            throw new DomainException('The provider outcome is past the idempotency window and requires reconciliation.');
        }

        return $result['communication'];
    }

    public function newResend(User $actor, Communication $original): Communication
    {
        $actor->can('newResend', $original) || abort(403);

        $result = DB::transaction(function () use ($actor, $original): array {
            $locked = Communication::query()->whereKey($original->id)->lockForUpdate()->firstOrFail();
            $attempts = DeliveryAttempt::query()->where('communication_id', $locked->id)
                ->where('idempotency_key', 'communication:'.$locked->id)
                ->orderBy('attempt')->lockForUpdate()->get();
            if (! $this->idempotencyWindow->hasAuthoritativeOutcome($attempts)
                && $this->idempotencyWindow->hasUnresolvedUncertainty($attempts)) {
                $now = now()->toImmutable();
                $expiresAt = $this->idempotencyWindow->anchor($locked, $attempts, $now);
                if ($this->idempotencyWindow->requiresReconciliation($attempts, $expiresAt, $now)) {
                    $this->idempotencyWindow->markReconciliationRequired($locked, $attempts, $expiresAt);

                    return ['communication' => $locked, 'blocked' => 'reconciliation'];
                }

                return ['communication' => $locked, 'blocked' => 'uncertain'];
            }

            $copy = Communication::query()->create([
                'property_id' => $locked->property_id,
                'guest_id' => $locked->guest_id,
                'reservation_id' => $locked->reservation_id,
                'channel' => $locked->channel,
                'direction' => 'outbound',
                'purpose' => $locked->purpose,
                'template_key' => $locked->template_key,
                'template_version' => $locked->template_version,
                'locale' => $locked->locale,
                'status' => 'queued',
                'subject' => $locked->subject,
                'body' => $locked->body,
                'content_checksum' => $locked->content_checksum,
                'automation_key' => 'manual-resend:'.$locked->id.':'.Str::uuid(),
                'metadata' => [
                    ...($locked->metadata ?? []),
                    'resend_of_communication_id' => $locked->id,
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

            return ['communication' => $copy, 'blocked' => null];
        }, 3);
        if ($result['blocked'] === 'reconciliation') {
            throw new DomainException('The provider outcome is past the idempotency window and requires reconciliation.');
        }
        if ($result['blocked'] === 'uncertain') {
            throw new DomainException('An uncertain provider outcome must be retried with its original identity or reconciled.');
        }

        return $result['communication'];
    }

    public function testSend(User $actor, Property $property, string $recipient, string $subject, string $body): Communication
    {
        $actor->can('testSend', [Communication::class, $property]) || abort(403);

        return DB::transaction(function () use ($actor, $property, $recipient, $subject, $body): Communication {
            $communication = Communication::query()->create([
                'property_id' => $property->id,
                'channel' => 'email',
                'direction' => 'outbound',
                'purpose' => 'test',
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
