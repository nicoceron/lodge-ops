<?php

namespace App\Services\Projections;

use App\Enums\AllocationStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Enums\ResourceType;
use App\Enums\TaskStatus;
use App\Models\Allocation;
use App\Models\OperationalTask;
use App\Models\Reservation;
use App\Models\Resource;
use App\Support\Projections\StaffProjectionVisibility;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class DashboardProjectionService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly StaffProjectionVisibility $visibility,
    ) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $now = CarbonImmutable::now($this->context->tenant()->timezone);
        $start = $now->startOfDay()->utc();
        $end = $now->addDay()->startOfDay()->utc();
        $readinessEnd = $now->addDays(7)->endOfDay()->utc();
        $propertyId = $this->context->membership()?->property_id;

        $arrivals = Reservation::query()
            ->with(['primaryGuest', 'allocations.resource', 'payments'])
            ->when($propertyId, fn ($query) => $query->where('property_id', $propertyId))
            ->whereIn('status', [ReservationStatus::Confirmed, ReservationStatus::CheckedIn])
            ->where('starts_at', '>=', $start)
            ->where('starts_at', '<', $end)
            ->orderBy('starts_at')
            ->get();
        $upcoming = Reservation::query()
            ->with(['primaryGuest', 'allocations.resource', 'payments'])
            ->when($propertyId, fn ($query) => $query->where('property_id', $propertyId))
            ->whereIn('status', [ReservationStatus::Confirmed, ReservationStatus::CheckedIn])
            ->where('starts_at', '>=', $start)
            ->where('starts_at', '<=', $readinessEnd)
            ->get();
        $activeRooms = Resource::query()
            ->when($propertyId, fn ($query) => $query->where('property_id', $propertyId))
            ->where('type', ResourceType::Room)
            ->where('is_active', true)
            ->count();
        $occupiedRooms = Allocation::query()
            ->where('status', AllocationStatus::Confirmed)
            ->where('starts_at', '<=', $now->utc())
            ->where('ends_at', '>', $now->utc())
            ->whereHas('resource', fn ($query) => $query
                ->where('type', ResourceType::Room)
                ->when($propertyId, fn ($query) => $query->where('property_id', $propertyId)))
            ->distinct()
            ->count('resource_id');
        $openTasks = OperationalTask::query()
            ->when($propertyId, fn ($query) => $query->where('property_id', $propertyId))
            ->whereNotIn('status', [TaskStatus::Done, TaskStatus::Cancelled])
            ->count();
        $tasks = OperationalTask::query()
            ->with('assignee:id,name')
            ->when($propertyId, fn ($query) => $query->where('property_id', $propertyId))
            ->whereNotIn('status', [TaskStatus::Done, TaskStatus::Cancelled])
            ->orderByRaw("case priority when 'urgent' then 1 when 'high' then 2 when 'normal' then 3 else 4 end")
            ->orderBy('due_at')
            ->limit(4)
            ->get()
            ->map(fn (OperationalTask $task): array => [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status->value,
                'priority' => $task->priority,
                'due_at' => $task->due_at?->toIso8601String(),
                'assignee' => $task->assignee ? [
                    'id' => $task->assignee->id,
                    'name' => $task->assignee->name,
                ] : null,
            ]);

        $departures = Reservation::query()
            ->when($propertyId, fn ($query) => $query->where('property_id', $propertyId))
            ->whereIn('status', [ReservationStatus::Confirmed, ReservationStatus::CheckedIn])
            ->where('ends_at', '>=', $start)
            ->where('ends_at', '<', $end)
            ->count();
        $inHouse = Reservation::query()
            ->when($propertyId, fn ($query) => $query->where('property_id', $propertyId))
            ->where('status', ReservationStatus::CheckedIn)
            ->where('starts_at', '<=', $now->utc())
            ->where('ends_at', '>', $now->utc())
            ->count();

        $readiness = collect([
            $this->readinessItem('guest_details', 'Guest details', $upcoming, fn (Reservation $reservation) => $reservation->primary_guest_id !== null),
            $this->readinessItem('room_assignments', 'Room assignments', $upcoming, fn (Reservation $reservation) => $this->hasResourceType($reservation, ResourceType::Room)),
            $this->readinessItem('guide_assignments', 'Guide assignments', $upcoming, fn (Reservation $reservation) => $this->hasResourceType($reservation, ResourceType::Guide)),
            $this->readinessItem('payments', 'Payments', $upcoming, fn (Reservation $reservation) => $this->balance($reservation) <= 0),
            $this->readinessItem('kitchen_brief', 'Kitchen brief', $upcoming, fn (Reservation $reservation) => $reservation->primaryGuest?->preferences !== null),
        ]);
        $readinessComplete = (int) $readiness->sum('complete');
        $readinessTotal = (int) $readiness->sum('total');

        return [
            'date' => $now->toDateString(),
            'timezone' => $this->context->tenant()->timezone,
            'arrivals' => $arrivals->count(),
            'departures' => $departures,
            'in_house' => $inHouse,
            'active_resources' => $activeRooms,
            'active_rooms' => $activeRooms,
            'occupied_rooms' => $occupiedRooms,
            'open_tasks' => $openTasks,
            'occupancy_percent' => $activeRooms > 0 ? round(($occupiedRooms / $activeRooms) * 100, 1) : 0.0,
            'needs_attention' => $upcoming->filter(fn (Reservation $reservation) => ! $this->hasResourceType($reservation, ResourceType::Room) || $this->balance($reservation) > 0)->count(),
            'arrival_parties' => $arrivals->map(fn (Reservation $reservation) => $this->arrival($reservation))->values(),
            'readiness' => [
                'complete' => $readinessComplete,
                'total' => $readinessTotal,
                'percent' => $readinessTotal > 0 ? round(($readinessComplete / $readinessTotal) * 100) : 100,
                'items' => $readiness,
            ],
            'tasks' => $tasks,
        ];
    }

    /** @param Collection<int, Reservation> $reservations */
    private function readinessItem(string $key, string $label, Collection $reservations, callable $complete): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'complete' => $reservations->filter($complete)->count(),
            'total' => $reservations->count(),
        ];
    }

    private function hasResourceType(Reservation $reservation, ResourceType $type): bool
    {
        return $reservation->allocations->contains(
            fn (Allocation $allocation) => $allocation->status !== AllocationStatus::Released
                && $allocation->resource?->type === $type,
        );
    }

    private function balance(Reservation $reservation): int
    {
        $paid = $reservation->payments
            ->where('status', PaymentStatus::Succeeded)
            ->where('currency', $reservation->currency)
            ->sum('amount_minor');

        return max(0, $reservation->total_minor - (int) $paid);
    }

    /** @return array<string, mixed> */
    private function arrival(Reservation $reservation): array
    {
        $hasRoom = $this->hasResourceType($reservation, ResourceType::Room);
        $balance = $this->balance($reservation);
        $readiness = ! $hasRoom ? 'blocked' : ($balance > 0 || $reservation->primary_guest_id === null ? 'attention' : 'ready');
        $arrival = [
            'id' => $reservation->id,
            'confirmation_number' => $reservation->confirmation_number,
            'starts_at' => $reservation->starts_at->toIso8601String(),
            'ends_at' => $reservation->ends_at->toIso8601String(),
            'party_size' => $reservation->adults + $reservation->children,
            'nights' => max(1, (int) $reservation->starts_at
                ->timezone($this->context->tenant()->timezone)
                ->startOfDay()
                ->diffInDays($reservation->ends_at->timezone($this->context->tenant()->timezone)->startOfDay())),
            'readiness' => $readiness,
            'room_names' => $reservation->allocations
                ->filter(fn (Allocation $allocation) => $allocation->resource?->type === ResourceType::Room)
                ->pluck('resource.name')
                ->filter()
                ->values(),
        ];

        if ($this->visibility->canSeeGuestIdentity()) {
            $arrival['guest_name'] = $reservation->primaryGuest
                ? trim("{$reservation->primaryGuest->first_name} {$reservation->primaryGuest->last_name}")
                : null;
        }

        return $arrival;
    }
}
