<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AllocationStatus;
use App\Http\Controllers\Controller;
use App\Models\Allocation;
use App\Models\OperationalTask;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\ResourceBlock;
use App\Models\ServiceOccurrence;
use App\Support\Projections\StaffProjectionVisibility;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CalendarController extends Controller
{
    public function __invoke(
        Request $request,
        TenantContext $context,
        StaffProjectionVisibility $visibility,
    ): JsonResponse {
        $this->authorize('viewAny', Reservation::class);
        $validated = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
            'property_id' => ['nullable', 'uuid'],
        ]);
        $start = CarbonImmutable::parse($validated['start'])->utc();
        $end = CarbonImmutable::parse($validated['end'])->utc();

        if ($start->diffInDays($end) > 92) {
            throw ValidationException::withMessages(['end' => 'Calendar windows may not exceed 92 days.']);
        }

        $propertyId = $validated['property_id'] ?? null;
        $resources = Resource::query()
            ->when($propertyId, fn ($query) => $query->where('property_id', $propertyId))
            ->where('is_active', true)
            ->orderBy('type')
            ->orderBy('name')
            ->get();
        $allocations = Allocation::query()
            ->with(['resource:id,name,type', 'reservation:id,confirmation_number'])
            ->where('status', '!=', AllocationStatus::Released)
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->when($propertyId, fn ($query) => $query->whereHas('reservation', fn ($query) => $query->where('property_id', $propertyId)))
            ->get();
        $events = collect();

        Reservation::query()
            ->with('primaryGuest:id,first_name,last_name')
            ->when($propertyId, fn ($query) => $query->where('property_id', $propertyId))
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->get()
            ->each(function (Reservation $reservation) use ($events, $allocations, $visibility): void {
                $title = $reservation->confirmation_number;

                if ($visibility->canSeeGuestIdentity() && $reservation->primaryGuest) {
                    $title = trim("{$reservation->primaryGuest->first_name} {$reservation->primaryGuest->last_name}");
                }

                $events->push([
                    'id' => $reservation->id,
                    'type' => 'reservation',
                    'title' => $title,
                    'start' => $reservation->starts_at->toIso8601String(),
                    'end' => $reservation->ends_at->toIso8601String(),
                    'status' => $reservation->status->value,
                    'property_id' => $reservation->property_id,
                    'resource_ids' => $allocations
                        ->where('reservation_id', $reservation->id)
                        ->pluck('resource_id')
                        ->filter()
                        ->unique()
                        ->values(),
                ]);
            });

        ResourceBlock::query()
            ->with('resource:id,property_id')
            ->whereHas('resource', fn ($query) => $query->when($propertyId, fn ($query) => $query->where('property_id', $propertyId)))
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->get()
            ->each(fn (ResourceBlock $block) => $events->push([
                'id' => $block->id,
                'type' => 'resource_block',
                'title' => $block->reason,
                'start' => $block->starts_at->toIso8601String(),
                'end' => $block->ends_at->toIso8601String(),
                'status' => 'blocked',
                'resource_ids' => [$block->resource_id],
                'property_id' => $block->resource->property_id,
            ]));

        ServiceOccurrence::query()
            ->with(['program:id,name', 'allocations:id,service_occurrence_id,resource_id'])
            ->when($propertyId, fn ($query) => $query->where('property_id', $propertyId))
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->get()
            ->each(fn (ServiceOccurrence $occurrence) => $events->push([
                'id' => $occurrence->id,
                'type' => 'activity',
                'title' => $occurrence->program->name,
                'start' => $occurrence->starts_at->toIso8601String(),
                'end' => $occurrence->ends_at->toIso8601String(),
                'status' => $occurrence->is_cancelled ? 'cancelled' : 'scheduled',
                'property_id' => $occurrence->property_id,
                'resource_ids' => $occurrence->allocations->pluck('resource_id')->filter()->unique()->values(),
            ]));

        OperationalTask::query()
            ->when($propertyId, fn ($query) => $query->where('property_id', $propertyId))
            ->whereNotNull('due_at')
            ->where('due_at', '>=', $start)
            ->where('due_at', '<', $end)
            ->get()
            ->each(fn (OperationalTask $task) => $events->push([
                'id' => $task->id,
                'type' => 'task',
                'title' => $task->title,
                'start' => $task->due_at->toIso8601String(),
                'end' => $task->due_at->toIso8601String(),
                'status' => $task->status->value,
                'property_id' => $task->property_id,
                'resource_ids' => [],
            ]));

        $unassignedReservations = $events
            ->where('type', 'reservation')
            ->filter(fn (array $event) => collect($event['resource_ids'])->isEmpty())
            ->count();

        return response()->json([
            'data' => $events->sortBy('start')->values(),
            'range' => [
                'start' => $start->toIso8601String(),
                'end' => $end->toIso8601String(),
                'timezone' => $context->tenant()->timezone,
            ],
            'resources' => $resources->map(fn (Resource $resource): array => [
                'id' => $resource->id,
                'property_id' => $resource->property_id,
                'name' => $resource->name,
                'code' => $resource->code,
                'type' => $resource->type->value,
                'capacity' => $resource->capacity,
                'utilization_percent' => $this->utilization($resource, $allocations, $start, $end),
            ]),
            'allocations' => $allocations->map(fn (Allocation $allocation): array => [
                'id' => $allocation->id,
                'reservation_id' => $allocation->reservation_id,
                'service_occurrence_id' => $allocation->service_occurrence_id,
                'resource_id' => $allocation->resource_id,
                'status' => $allocation->status->value,
                'start' => $allocation->starts_at->toIso8601String(),
                'end' => $allocation->ends_at->toIso8601String(),
                'quantity' => $allocation->quantity,
            ]),
            'summary' => [
                'hard_conflicts' => $this->hardConflicts($allocations),
                'unassigned_reservations' => $unassignedReservations,
                'suggestions' => $unassignedReservations,
            ],
        ]);
    }

    /** @param Collection<int, Allocation> $allocations */
    private function utilization(Resource $resource, Collection $allocations, CarbonImmutable $start, CarbonImmutable $end): int
    {
        $rangeSeconds = max(1, $start->diffInSeconds($end));
        $usedSeconds = $allocations
            ->where('resource_id', $resource->id)
            ->sum(function (Allocation $allocation) use ($start, $end): int {
                $allocationStart = $allocation->starts_at->greaterThan($start) ? $allocation->starts_at : $start;
                $allocationEnd = $allocation->ends_at->lessThan($end) ? $allocation->ends_at : $end;

                return max(0, $allocationStart->diffInSeconds($allocationEnd));
            });

        return (int) min(100, round(($usedSeconds / $rangeSeconds) * 100));
    }

    /** @param Collection<int, Allocation> $allocations */
    private function hardConflicts(Collection $allocations): int
    {
        $conflicts = 0;

        foreach ($allocations->whereNotNull('resource_id')->groupBy('resource_id') as $resourceAllocations) {
            $ordered = $resourceAllocations->sortBy('starts_at')->values();

            for ($left = 0; $left < $ordered->count(); $left++) {
                for ($right = $left + 1; $right < $ordered->count(); $right++) {
                    if ($ordered[$right]->starts_at->greaterThanOrEqualTo($ordered[$left]->ends_at)) {
                        break;
                    }

                    if ($ordered[$right]->reservation_id !== $ordered[$left]->reservation_id) {
                        $conflicts++;
                    }
                }
            }
        }

        return $conflicts;
    }
}
