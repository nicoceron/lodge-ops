<?php

namespace App\Services;

use App\Enums\MembershipRole;
use App\Enums\ReservationStatus;
use App\Enums\TaskStatus;
use App\Models\Membership;
use App\Models\OperationalTask;
use App\Models\OperationalTaskEvent;
use App\Models\Property;
use App\Models\Reservation;
use App\Services\Automation\OutboxRecorder;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TaskLifecycleService
{
    public function __construct(
        private readonly OutboxRecorder $outbox,
        private readonly OperationalTaskAssigneeService $assignees,
        private readonly OperationalTaskAccess $access,
        private readonly TenantContext $tenantContext,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, ?int $actorId): OperationalTask
    {
        return DB::transaction(function () use ($data, $actorId): OperationalTask {
            $propertyId = (string) ($data['property_id'] ?? '');
            $this->assertActorMaySchedule($actorId, $propertyId);
            Property::query()->whereKey($propertyId)->where('is_active', true)->firstOrFail();

            $reservationId = filled($data['reservation_id'] ?? null) ? (string) $data['reservation_id'] : null;
            if ($reservationId !== null && ! Reservation::query()->whereKey($reservationId)->where('property_id', $propertyId)->exists()) {
                throw ValidationException::withMessages(['reservation_id' => 'The reservation must belong to the selected property.']);
            }

            $task = new OperationalTask;
            $task->forceFill([
                ...collect($data)->only(['property_id', 'reservation_id', 'title', 'description', 'priority', 'due_at', 'metadata'])->all(),
                'reservation_id' => $reservationId,
                'status' => TaskStatus::Todo,
                'priority' => $data['priority'] ?? 'normal',
                'revision' => 1,
            ]);
            $task->save();
            $this->event($task, 'created', TaskStatus::Todo, TaskStatus::Todo, null, $actorId);
            $this->outbox->record('operational_task', $task->id, 'operational_task.created', $this->outboxPayload($task));

            if (filled($data['assignee_id'] ?? null)) {
                $this->assignees->assertEligible($task, (int) $data['assignee_id']);
                $task->forceFill(['assignee_id' => (int) $data['assignee_id'], 'revision' => 2])->save();
                $this->event($task, 'assigned', TaskStatus::Todo, TaskStatus::Todo, null, $actorId);
                $this->outbox->record('operational_task', $task->id, 'operational_task.assigned', $this->outboxPayload($task));
            }

            return $task->fresh(['assignee', 'events']);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function transition(OperationalTask $task, string $action, array $data, ?int $actorId): OperationalTask
    {
        return DB::transaction(function () use ($task, $action, $data, $actorId): OperationalTask {
            $locked = OperationalTask::query()->lockForUpdate()->findOrFail($task->id);
            $this->assertActorMayManage($locked, $actorId);
            $expectedRevision = (int) ($data['expected_revision'] ?? 0);
            if ($expectedRevision !== $locked->revision) {
                throw ValidationException::withMessages(['expected_revision' => 'This task changed. Refresh and retry the action.']);
            }

            $from = $locked->status;
            if ($action === 'assign') {
                $this->assignees->assertEligible($locked, (int) $data['assignee_id']);
            }
            if ($action === 'reopen') {
                $this->assertReservationMayReopenTask($locked, $data, $actorId);
            }
            [$to, $attributes, $event] = $this->transitionAttributes($locked, $action, $data);
            $locked->forceFill([...$attributes, 'status' => $to, 'revision' => $locked->revision + 1]);
            $locked->save();
            $this->event($locked, $event, $from, $to, $data['reason'] ?? null, $actorId);
            $this->outbox->record('operational_task', $locked->id, 'operational_task.'.$event, $this->outboxPayload($locked));

            return $locked->fresh(['assignee', 'events']);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function updateDetails(OperationalTask $task, array $data, ?int $actorId): OperationalTask
    {
        return DB::transaction(function () use ($task, $data, $actorId): OperationalTask {
            $locked = OperationalTask::query()->lockForUpdate()->findOrFail($task->id);
            $this->assertActorMayManage($locked, $actorId);
            if ((int) ($data['expected_revision'] ?? 0) !== $locked->revision) {
                throw ValidationException::withMessages(['expected_revision' => 'This task changed. Refresh and retry the update.']);
            }
            $before = $locked->only(['title', 'description', 'priority', 'due_at', 'metadata']);
            $locked->fill(collect($data)->only(['title', 'description', 'priority', 'due_at', 'metadata'])->all());
            $locked->revision++;
            $locked->save();
            OperationalTaskEvent::query()->create([
                'operational_task_id' => $locked->id,
                'actor_id' => $actorId,
                'type' => 'details_updated',
                'from_status' => $locked->status->value,
                'to_status' => $locked->status->value,
                'snapshot' => ['revision' => $locked->revision, 'before' => $before],
                'occurred_at' => now(),
            ]);
            $this->outbox->record('operational_task', $locked->id, 'operational_task.details_updated', $this->outboxPayload($locked));

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
                ->where('status', TaskStatus::Todo)
                ->whereNull('started_at')->whereNull('failed_at')->whereNull('escalated_at')
                ->lockForUpdate()->get();
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

    /** @param array<string, mixed> $data */
    private function assertReservationMayReopenTask(OperationalTask $task, array $data, ?int $actorId): void
    {
        $reservation = $task->reservation()->first();
        if ($reservation === null || ! in_array($reservation->status, [ReservationStatus::Cancelled, ReservationStatus::NoShow, ReservationStatus::CheckedOut], true)) {
            return;
        }

        $authorized = ($data['reservation_reopen_authorized'] ?? false) === true
            && $actorId !== null
            && Membership::query()->where('user_id', $actorId)->where('is_active', true)
                ->whereIn('role', [MembershipRole::Administrator->value, MembershipRole::Manager->value])
                ->where(fn ($query) => $query->whereNull('property_id')->orWhere('property_id', $task->property_id))
                ->exists();
        if (! $authorized) {
            throw ValidationException::withMessages([
                'reservation_reopen_authorized' => 'Tasks on a terminal reservation require explicit reservation-reopen authority from an active property manager.',
            ]);
        }
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

    private function assertActorMaySchedule(?int $actorId, string $propertyId): void
    {
        if ($actorId === null) {
            throw ValidationException::withMessages(['actor' => 'An active operations scheduler is required.']);
        }
        $membership = Membership::query()->where('user_id', $actorId)->where('is_active', true)->first();
        if ($membership === null || ! $membership->role->canScheduleOperations()
            || ($membership->property_id !== null && $membership->property_id !== $propertyId)
            || ! $this->tenantContext->canAccessProperty($propertyId)) {
            throw ValidationException::withMessages(['property_id' => 'The actor cannot schedule tasks for this property.']);
        }
    }

    private function assertActorMayManage(OperationalTask $task, ?int $actorId): void
    {
        if ($actorId === null) {
            return;
        }
        $membership = Membership::query()->where('user_id', $actorId)->where('is_active', true)->first();
        if ($membership === null || ! $membership->role->canManageOperations()
            || ($membership->property_id !== null && $membership->property_id !== $task->property_id)
            || ! $this->access->allows($membership->user, $task, $membership->role)) {
            throw ValidationException::withMessages(['task' => 'The actor cannot mutate this operational task.']);
        }
    }

    /** @return array<string, mixed> */
    private function outboxPayload(OperationalTask $task): array
    {
        return [
            'task_id' => $task->id,
            'reservation_id' => $task->reservation_id,
            'property_id' => $task->property_id,
            'status' => $task->status->value,
            'revision' => $task->revision,
            'due_at' => $task->due_at?->toIso8601String(),
            'assignee_id' => $task->assignee_id,
        ];
    }
}
