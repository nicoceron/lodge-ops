<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OperationalTask;
use App\Models\Reservation;
use App\Models\ResourceBlock;
use App\Models\ServiceOccurrence;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CalendarController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Reservation::class);
        $validated = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
            'property_id' => ['nullable', 'uuid'],
        ]);
        $start = CarbonImmutable::parse($validated['start']);
        $end = CarbonImmutable::parse($validated['end']);

        if ($start->diffInDays($end) > 92) {
            throw ValidationException::withMessages(['end' => 'Calendar windows may not exceed 92 days.']);
        }

        $propertyId = $validated['property_id'] ?? null;
        $events = collect();

        Reservation::query()->with(['primaryGuest', 'allocations.resource'])
            ->when($propertyId, fn ($query) => $query->where('property_id', $propertyId))
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)->get()
            ->each(fn ($reservation) => $events->push([
                'id' => $reservation->id,
                'type' => 'reservation',
                'title' => $reservation->primaryGuest ? trim("{$reservation->primaryGuest->first_name} {$reservation->primaryGuest->last_name}") : $reservation->confirmation_number,
                'start' => $reservation->starts_at,
                'end' => $reservation->ends_at,
                'status' => $reservation->status->value,
                'property_id' => $reservation->property_id,
                'resource_ids' => $reservation->allocations->pluck('resource_id')->filter()->values(),
            ]));

        ResourceBlock::query()->with('resource')
            ->whereHas('resource', fn ($query) => $query->when($propertyId, fn ($query) => $query->where('property_id', $propertyId)))
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)->get()
            ->each(fn ($block) => $events->push([
                'id' => $block->id,
                'type' => 'resource_block',
                'title' => $block->reason,
                'start' => $block->starts_at,
                'end' => $block->ends_at,
                'resource_ids' => [$block->resource_id],
                'property_id' => $block->resource->property_id,
            ]));

        ServiceOccurrence::query()->with('program')
            ->when($propertyId, fn ($query) => $query->where('property_id', $propertyId))
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)->get()
            ->each(fn ($occurrence) => $events->push([
                'id' => $occurrence->id,
                'type' => 'activity',
                'title' => $occurrence->program->name,
                'start' => $occurrence->starts_at,
                'end' => $occurrence->ends_at,
                'status' => $occurrence->is_cancelled ? 'cancelled' : 'scheduled',
                'property_id' => $occurrence->property_id,
            ]));

        OperationalTask::query()->when($propertyId, fn ($query) => $query->where('property_id', $propertyId))
            ->whereNotNull('due_at')->where('due_at', '>=', $start)->where('due_at', '<', $end)->get()
            ->each(fn ($task) => $events->push([
                'id' => $task->id,
                'type' => 'task',
                'title' => $task->title,
                'start' => $task->due_at,
                'end' => $task->due_at,
                'status' => $task->status->value,
                'property_id' => $task->property_id,
            ]));

        return response()->json(['data' => $events->sortBy('start')->values()]);
    }
}
