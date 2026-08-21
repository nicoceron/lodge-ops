<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Enums\TaskStatus;
use App\Models\ChecklistTemplate;
use App\Models\ChecklistTemplateVersion;
use App\Models\OperationalTask;
use App\Models\OperationalTaskEvent;
use App\Models\Reservation;
use App\Models\ReservationChecklistException;
use App\Services\Automation\OutboxRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ChecklistWorkflowService
{
    public function __construct(private readonly OutboxRecorder $outbox) {}

    /** @param list<array<string, mixed>> $items */
    public function publish(ChecklistTemplate $template, array $items, ?int $actorId): ChecklistTemplateVersion
    {
        return DB::transaction(function () use ($template, $items, $actorId): ChecklistTemplateVersion {
            $locked = ChecklistTemplate::query()->lockForUpdate()->findOrFail($template->id);
            if ($locked->state === 'retired') {
                throw ValidationException::withMessages(['template' => 'A retired checklist cannot be published.']);
            }
            if ($items === []) {
                throw ValidationException::withMessages(['items' => 'Publish at least one checklist item.']);
            }
            $version = $locked->versions()->create([
                'version' => $locked->latest_version + 1,
                'state' => 'published',
                'created_by' => $actorId,
                'published_by' => $actorId,
                'published_at' => now(),
            ]);
            foreach ($items as $index => $item) {
                $title = trim((string) ($item['title'] ?? ''));
                if ($title === '') {
                    throw ValidationException::withMessages(["items.{$index}.title" => 'Every checklist item requires a title.']);
                }
                $version->items()->create([
                    'title' => $title,
                    'description' => $item['description'] ?? null,
                    'priority' => $item['priority'] ?? 'normal',
                    'due_offset_minutes' => (int) ($item['due_offset_minutes'] ?? 0),
                    'sort_order' => $index,
                ]);
            }
            $locked->update(['latest_version' => $version->version, 'state' => 'published']);

            return $version->load('items');
        }, 3);
    }

    public function retire(ChecklistTemplate $template): void
    {
        DB::transaction(function () use ($template): void {
            $locked = ChecklistTemplate::query()->lockForUpdate()->findOrFail($template->id);
            $locked->versions()->where('state', 'published')->update(['state' => 'retired', 'retired_at' => now()]);
            $locked->update(['state' => 'retired']);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function saveException(Reservation $reservation, array $data, ?int $actorId, ?ReservationChecklistException $exception = null): ReservationChecklistException
    {
        return DB::transaction(function () use ($reservation, $data, $actorId, $exception): ReservationChecklistException {
            $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            if ($exception !== null && $exception->reservation_id !== $locked->id) {
                throw ValidationException::withMessages(['exception' => 'The exception is outside this reservation.']);
            }
            $this->validateException($locked, $data);
            $record = $exception === null
                ? new ReservationChecklistException
                : ReservationChecklistException::query()->lockForUpdate()->findOrFail($exception->id);
            $record->forceFill([
                ...collect($data)->only([
                    'checklist_template_item_id', 'operation', 'title', 'description', 'priority',
                    'due_offset_minutes', 'sort_order',
                ])->all(),
                'reservation_id' => $locked->id,
                'created_by' => $record->created_by ?? $actorId,
            ])->save();

            return $record->fresh('templateItem');
        }, 3);
    }

    public function deleteException(Reservation $reservation, ReservationChecklistException $exception): void
    {
        if ($exception->reservation_id !== $reservation->id) {
            throw ValidationException::withMessages(['exception' => 'The exception is outside this reservation.']);
        }
        $exception->delete();
    }

    /** @param list<array<string, mixed>> $rows */
    public function replaceExceptions(Reservation $reservation, array $rows, ?int $actorId): void
    {
        DB::transaction(function () use ($reservation, $rows, $actorId): void {
            $keep = collect();
            foreach ($rows as $index => $row) {
                $row['sort_order'] = $index;
                $existing = isset($row['id'])
                    ? ReservationChecklistException::query()->where('reservation_id', $reservation->id)->find($row['id'])
                    : null;
                $keep->push($this->saveException($reservation, $row, $actorId, $existing)->id);
            }
            ReservationChecklistException::query()->where('reservation_id', $reservation->id)
                ->when($keep->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $keep))
                ->delete();
        }, 3);
    }

    /** @return array{created: int, superseded: int, generation: int} */
    public function generate(Reservation $reservation, ChecklistTemplateVersion $version, ?int $actorId): array
    {
        return DB::transaction(function () use ($reservation, $version, $actorId): array {
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            if (in_array($reservation->status, [ReservationStatus::Cancelled, ReservationStatus::NoShow, ReservationStatus::CheckedOut], true)) {
                throw ValidationException::withMessages(['reservation' => 'A terminal reservation cannot generate or regenerate operational checklist tasks.']);
            }
            $version = ChecklistTemplateVersion::query()->with(['template', 'items'])->where('state', 'published')->findOrFail($version->id);
            if ($version->template->property_id !== $reservation->property_id
                || ($version->template->program_id !== null && $version->template->program_id !== $reservation->program_id)) {
                throw ValidationException::withMessages(['version_id' => 'The checklist version does not apply to this reservation.']);
            }
            $generation = ((int) OperationalTask::query()->where('reservation_id', $reservation->id)->max('generation')) + 1;
            $superseded = 0;
            $lineageVersionIds = ChecklistTemplateVersion::query()
                ->where('checklist_template_id', $version->checklist_template_id)
                ->pluck('id');
            $previous = OperationalTask::query()->where('reservation_id', $reservation->id)
                ->whereIn('checklist_template_version_id', $lineageVersionIds)
                ->where('metadata->checklist_role', $version->template->role)
                ->where('status', TaskStatus::Todo)
                ->whereNull('started_at')->whereNull('failed_at')->whereNull('escalated_at')
                ->lockForUpdate()->get();
            foreach ($previous as $task) {
                $from = $task->status;
                $task->update(['status' => TaskStatus::Superseded, 'superseded_at' => now(), 'revision' => $task->revision + 1]);
                OperationalTaskEvent::query()->create([
                    'operational_task_id' => $task->id, 'actor_id' => $actorId, 'type' => 'superseded',
                    'from_status' => $from->value, 'to_status' => TaskStatus::Superseded->value,
                    'reason' => 'Checklist regenerated from a newer published version.',
                    'snapshot' => ['generation' => $task->generation], 'occurred_at' => now(),
                ]);
                $superseded++;
            }

            $exceptions = ReservationChecklistException::query()->where('reservation_id', $reservation->id)->get();
            $excluded = $exceptions->where('operation', 'remove')->pluck('checklist_template_item_id')->filter();
            $overrides = $exceptions->whereIn('operation', ['edit', 'reorder'])->keyBy('checklist_template_item_id');
            $created = collect();
            foreach ($version->items->whereNotIn('id', $excluded) as $item) {
                $override = $overrides->get($item->id);
                $description = $item->description;
                $dueOffsetMinutes = $item->due_offset_minutes;
                $sortOrder = $item->sort_order;
                if ($override instanceof ReservationChecklistException) {
                    $description = $override->description ?? $description;
                    $dueOffsetMinutes = $override->due_offset_minutes ?? $dueOffsetMinutes;
                    $sortOrder = $override->sort_order;
                }
                $created->push(OperationalTask::query()->create([
                    'property_id' => $reservation->property_id,
                    'reservation_id' => $reservation->id,
                    'checklist_template_version_id' => $version->id,
                    'checklist_template_item_id' => $item->id,
                    'title' => $override?->title ?: $item->title,
                    'description' => $description,
                    'priority' => $override?->priority ?: $item->priority,
                    'due_at' => $reservation->starts_at->addMinutes($dueOffsetMinutes),
                    'status' => TaskStatus::Todo,
                    'generation' => $generation,
                    'metadata' => ['checklist_role' => $version->template->role, 'sort_order' => $sortOrder],
                ]));
            }
            foreach ($exceptions->where('operation', 'add') as $exception) {
                $created->push(OperationalTask::query()->create([
                    'property_id' => $reservation->property_id, 'reservation_id' => $reservation->id,
                    'checklist_template_version_id' => $version->id,
                    'reservation_checklist_exception_id' => $exception->id,
                    'title' => $exception->title, 'description' => $exception->description,
                    'priority' => $exception->priority ?? 'normal',
                    'due_at' => $reservation->starts_at->addMinutes($exception->due_offset_minutes ?? 0),
                    'status' => TaskStatus::Todo, 'generation' => $generation,
                    'metadata' => ['checklist_role' => $version->template->role, 'sort_order' => $exception->sort_order],
                ]));
            }
            $this->outbox->record('reservation', $reservation->id, 'reservation.checklist.generated', [
                'reservation_id' => $reservation->id, 'checklist_template_version_id' => $version->id,
                'generation' => $generation, 'task_ids' => $created->pluck('id')->all(), 'superseded_count' => $superseded,
            ]);

            return ['created' => $created->count(), 'superseded' => $superseded, 'generation' => $generation];
        }, 3);
    }

    /** @param array<string, mixed> $data */
    private function validateException(Reservation $reservation, array $data): void
    {
        $operation = (string) ($data['operation'] ?? '');
        if ($operation === 'add') {
            if (blank($data['title'] ?? null) || ! empty($data['checklist_template_item_id'])) {
                throw ValidationException::withMessages(['title' => 'An added exception needs a title and cannot target a template item.']);
            }

            return;
        }

        $itemId = $data['checklist_template_item_id'] ?? null;
        $applicable = filled($itemId) && ChecklistTemplateVersion::query()
            ->where('state', 'published')
            ->whereHas('items', fn ($query) => $query->whereKey($itemId))
            ->whereHas('template', fn ($query) => $query->where('property_id', $reservation->property_id)
                ->where(fn ($scope) => $scope->whereNull('program_id')->orWhere('program_id', $reservation->program_id)))
            ->exists();
        if (! $applicable) {
            throw ValidationException::withMessages(['checklist_template_item_id' => 'Select an item from a published checklist applicable to this reservation and property.']);
        }
    }
}
