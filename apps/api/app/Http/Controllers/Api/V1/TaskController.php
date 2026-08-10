<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\OperationalTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class TaskController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', OperationalTask::class);

        $tasks = OperationalTask::query()
            ->with('assignee')
            ->when($request->query('status'), fn ($query, $value) => $query->where('status', $value))
            ->when($request->query('property_id'), fn ($query, $value) => $query->where('property_id', $value))
            ->when($request->query('assignee_id'), fn ($query, $value) => $query->where('assignee_id', $value))
            ->orderByRaw("case priority when 'urgent' then 1 when 'high' then 2 when 'normal' then 3 else 4 end")
            ->orderBy('due_at')
            ->paginate(min((int) $request->integer('per_page', 50), 100));

        return TaskResource::collection($tasks);
    }

    public function store(StoreTaskRequest $request): TaskResource
    {
        $this->authorize('create', OperationalTask::class);
        $task = OperationalTask::query()->create($this->withCompletionTimestamp([
            'status' => TaskStatus::Todo->value,
            'priority' => 'normal',
            ...$request->validated(),
        ]));

        return new TaskResource($task->load('assignee'));
    }

    public function show(OperationalTask $task): TaskResource
    {
        $this->authorize('view', $task);

        return new TaskResource($task->load('assignee'));
    }

    public function update(UpdateTaskRequest $request, OperationalTask $task): TaskResource
    {
        $this->authorize('update', $task);
        $task->update($this->withCompletionTimestamp($request->validated(), $task));

        return new TaskResource($task->fresh()->load('assignee'));
    }

    public function destroy(OperationalTask $task): Response
    {
        $this->authorize('delete', $task);
        $task->delete();

        return response()->noContent();
    }

    private function withCompletionTimestamp(array $data, ?OperationalTask $task = null): array
    {
        if (($data['status'] ?? null) === TaskStatus::Done->value && $task?->completed_at === null) {
            $data['completed_at'] = now();
        } elseif (array_key_exists('status', $data) && $data['status'] !== TaskStatus::Done->value) {
            $data['completed_at'] = null;
        }

        return $data;
    }
}
