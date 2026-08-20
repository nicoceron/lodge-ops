<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\OperationalTask;
use App\Models\Reservation;
use App\Models\User;
use App\Services\OperationalTaskAccess;
use App\Services\OperationalTaskAssigneeService;
use App\Services\TaskLifecycleService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TaskController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', OperationalTask::class);

        $query = OperationalTask::query();
        $membership = app(TenantContext::class)->membership();
        $user = $request->user();
        abort_unless($membership?->role !== null && $user instanceof User, 403);

        $tasks = app(OperationalTaskAccess::class)->scope($query, $user, $membership->role)
            ->with('assignee')
            ->when($request->query('status'), fn ($query, $value) => $query->where('status', $value))
            ->when($request->query('property_id'), fn ($query, $value) => $query->where('property_id', $value))
            ->when($request->query('reservation_id'), fn ($query, $value) => $query->where('reservation_id', $value))
            ->when($request->query('assignee_id'), fn ($query, $value) => $query->where('assignee_id', $value))
            ->orderByRaw("case priority when 'urgent' then 1 when 'high' then 2 when 'normal' then 3 else 4 end")
            ->orderBy('due_at')
            ->paginate(min((int) $request->integer('per_page', 50), 100));

        return TaskResource::collection($tasks);
    }

    public function store(StoreTaskRequest $request): TaskResource
    {
        $this->authorize('create', OperationalTask::class);
        $data = $request->validated();
        $membershipPropertyId = app(TenantContext::class)->membership()?->property_id;
        abort_if($membershipPropertyId !== null && $data['property_id'] !== $membershipPropertyId, 403);
        if (isset($data['reservation_id'])) {
            abort_unless(Reservation::query()
                ->whereKey($data['reservation_id'])
                ->where('property_id', $data['property_id'])
                ->exists(), 403);
        }
        $task = new OperationalTask;
        $task->forceFill([
            'status' => TaskStatus::Todo->value,
            'priority' => 'normal',
            ...$data,
        ]);
        if ($task->assignee_id !== null) {
            app(OperationalTaskAssigneeService::class)->assertEligible($task, $task->assignee_id);
        }
        $task->save();

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

        return new TaskResource(app(TaskLifecycleService::class)->updateDetails(
            $task,
            $request->validated(),
            $request->user()?->id,
        ));
    }

    public function destroy(Request $request, OperationalTask $task): JsonResponse
    {
        $this->authorize('delete', $task);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $cancelled = app(TaskLifecycleService::class)->transition($task, 'cancel', $data, $request->user()?->id);

        return response()->json(['data' => (new TaskResource($cancelled))->resolve($request)]);
    }
}
