<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\OperationalTask;
use App\Models\OperationalTaskEvent;
use App\Services\Automation\OutboxRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TaskLifecycleService
{
    public function __construct(private readonly OutboxRecorder $outbox) {}

    /** @param array<string, mixed> $data */
    public function transition(OperationalTask $task, string $action, array $data, ?int $actorId): OperationalTask
    {
        return DB::transaction(function () use ($task, $action, $data, $actorId): OperationalTask {
            $locked = OperationalTask::query()->lockForUpdate()->findOrFail($task->id);
            $expectedRevision = (int) ($data['expected_revision'] ?? 0);
            if ($expectedRevision !== $locked->revision) {
                throw ValidationException::withMessages(['expected_revision' => 'This task changed. Refresh and retry the action.']);
            }

            $from = $locked->status;
            [$to, $attributes, $event] = $this->transitionAttributes($locked, $action, $data);
            $locked->forceFill([...$attributes, 'status' => $to, 'revision' => $locked->revision + 1]);
            $locked->save();
            $this->event($locked, $event, $from, $to, $data['reason'] ?? null, $actorId);
            $this->outbox->record('operational_task', $locked->id, 'operational_task.'.$event, [
                'task_id' => $locked->id,
                'reservation_id' => $locked->reservation_id,
                'property_id' => $locked->property_id,
                'status' => $to->value,
                'due_at' => $locked->due_at?->toIso8601String(),
                'assignee_id' => $locked->assignee_id,
            ]);

            return $locked->fresh(['assignee', 'events']);
        }, 3);
    }

    public function cancelOpenForReservation(string $reservationId, string $reason, ?int $actorId): int
    {
        return DB::transaction(function () use ($reservationId, $reason, $actorId): int {
            $tasks = OperationalTask::query()->where('reservation_id', $reservationId)
                ->whereIn('status', [TaskStatus::Todo, TaskStatus::InProgress, TaskStatus::Blocked, TaskStatus::Failed])
                ->lockForUpdate()->get();
            foreach ($tasks as $task) {
                $from = $task->status;
                $task->update([
                    'status' => TaskStatus::Cancelled,
                    'cancellation_reason' => $reason,
                    'revision' => $task->revision + 1,
                ]);
                $this->event($task, 'cancelled', $from, TaskStatus::Cancelled, $reason, $actorId);
                $this->outbox->record('operational_task', $task->id, 'operational_task.cancelled', [
                    'task_id' => $task->id, 'reservation_id' => $reservationId,
                    'property_id' => $task->property_id, 'status' => TaskStatus::Cancelled->value,
                ]);
            }

            return $tasks->count();
        }, 3);
    }

    public function rebasePendingForReservation(string $reservationId, CarbonImmutable $oldStart, CarbonImmutable $newStart, ?int $actorId): int
    {
        $seconds = (int) round($oldStart->diffInSeconds($newStart, false));
        if ($seconds === 0) {
            return 0;
        }

        return DB::transaction(function () use ($reservationId, $seconds, $actorId): int {
            $tasks = OperationalTask::query()->where('reservation_id', $reservationId)
                ->whereNotNull('checklist_template_version_id')
                ->whereIn('status', [TaskStatus::Todo, TaskStatus::Blocked])
                ->whereNull('started_at')->lockForUpdate()->get();
            foreach ($tasks as $task) {
                $task->update([
                    'due_at' => $task->due_at?->addSeconds($seconds),
                    'revision' => $task->revision + 1,
                ]);
                $this->event($task, 'rescheduled', $task->status, $task->status, 'Reservation arrival changed.', $actorId);
                $this->outbox->record('operational_task', $task->id, 'operational_task.rescheduled', [
                    'task_id' => $task->id, 'reservation_id' => $reservationId,
                    'property_id' => $task->property_id, 'due_at' => $task->due_at?->toIso8601String(),
                ]);
            }

            return $tasks->count();
        }, 3);
    }

    /** @param array<string, mixed> $data @return array{TaskStatus, array<string, mixed>, string} */
    private function transitionAttributes(OperationalTask $task, string $action, array $data): array
    {
        return match ($action) {
            'assign' => $this->onlyFrom($task, [TaskStatus::Todo, TaskStatus::InProgress, TaskStatus::Blocked, TaskStatus::Failed], $task->status, ['assignee_id' => $data['assignee_id']], 'assigned'),
            'start' => $this->onlyFrom($task, [TaskStatus::Todo, TaskStatus::Blocked], TaskStatus::InProgress, ['started_at' => now()], 'started'),
            'complete' => $this->onlyFrom($task, [TaskStatus::Todo, TaskStatus::InProgress, TaskStatus::Blocked], TaskStatus::Done, ['completed_at' => now(), 'failure_reason' => null], 'completed'),
            'fail' => $this->onlyFrom($task, [TaskStatus::Todo, TaskStatus::InProgress, TaskStatus::Blocked], TaskStatus::Failed, ['failed_at' => now(), 'failure_reason' => $this->requiredReason($data)], 'failed'),
            'reopen' => $this->onlyFrom($task, [TaskStatus::Done, TaskStatus::Failed, TaskStatus::Cancelled], TaskStatus::Todo, ['completed_at' => null, 'reopened_at' => now(), 'cancellation_reason' => null], 'reopened'),
            'escalate' => $this->onlyFrom($task, [TaskStatus::Todo, TaskStatus::InProgress, TaskStatus::Blocked, TaskStatus::Failed], TaskStatus::Blocked, ['escalated_at' => now(), 'escalation_reason' => $this->requiredReason($data), 'priority' => 'urgent'], 'escalated'),
            'cancel' => $this->onlyFrom($task, [TaskStatus::Todo, TaskStatus::InProgress, TaskStatus::Blocked, TaskStatus::Failed], TaskStatus::Cancelled, ['cancellation_reason' => $this->requiredReason($data)], 'cancelled'),
            default => throw ValidationException::withMessages(['action' => 'Unsupported task lifecycle action.']),
        };
    }

    /** @param list<TaskStatus> $allowed @param array<string, mixed> $attributes @return array{TaskStatus, array<string, mixed>, string} */
    private function onlyFrom(OperationalTask $task, array $allowed, TaskStatus $to, array $attributes, string $event): array
    {
        if (! in_array($task->status, $allowed, true)) {
            throw ValidationException::withMessages(['action' => "A {$task->status->value} task cannot be {$event}."]);
        }

        return [$to, $attributes, $event];
    }

    /** @param array<string, mixed> $data */
    private function requiredReason(array $data): string
    {
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required for this action.']);
        }

        return $reason;
    }

    private function event(OperationalTask $task, string $type, TaskStatus $from, TaskStatus $to, ?string $reason, ?int $actorId): void
    {
        OperationalTaskEvent::query()->create([
            'operational_task_id' => $task->id,
            'actor_id' => $actorId,
            'type' => $type,
            'from_status' => $from->value,
            'to_status' => $to->value,
            'reason' => $reason,
            'snapshot' => ['revision' => $task->revision, 'priority' => $task->priority, 'assignee_id' => $task->assignee_id],
            'occurred_at' => now(),
        ]);
    }
}
