<?php

namespace App\Services\Projections;

use App\Enums\AllocationStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Enums\TaskStatus;
use App\Models\Allocation;
use App\Models\OperationalTask;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\User;
use App\Services\OperationalTaskAccess;
use App\Services\ProgramRequirementService;
use App\Support\Projections\StaffProjectionVisibility;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class DashboardProjectionService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly StaffProjectionVisibility $visibility,
        private readonly OperationalTaskAccess $taskAccess,
        private readonly ProgramRequirementService $programRequirements,
    ) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $membership = $this->context->membership();
        $cacheKey = hash('sha256', implode('|', [
            $this->context->tenant()->id,
            $membership->id,
            $membership->property_id ?? 'all-properties',
            $membership->role->value,
            $membership->user_id,
        ]));

        return Cache::remember(
            "dashboard-projection:v2:{$cacheKey}",
            15,
            fn (): array => $this->buildFresh(),
        );
    }

    /** @return array<string, mixed> */
    private function buildFresh(): array
    {
        $now = CarbonImmutable::now($this->context->tenant()->timezone);
        $start = $now->startOfDay()->utc();
        $end = $now->addDay()->startOfDay()->utc();
        $readinessEnd = $now->addDays(7)->endOfDay()->utc();
        $propertyId = $this->context->membership()?->property_id;

        $arrivals = Reservation::query()
            ->with(['primaryGuest', 'program.requirements.category', 'allocations.requestedCategory', 'allocations.resource.category', 'payments'])
            ->when($propertyId, fn ($query) => $query->where('property_id', $propertyId))
            ->whereIn('status', [ReservationStatus::Confirmed, ReservationStatus::CheckedIn])
            ->where('starts_at', '>=', $start)
            ->where('starts_at', '<', $end)
            ->orderBy('starts_at')
            ->get();
        $upcoming = Reservation::query()
            ->with(['primaryGuest', 'program.requirements.category', 'allocations.requestedCategory', 'allocations.resource.category', 'payments'])
            ->when($propertyId, fn ($query) => $query->where('property_id', $propertyId))
            ->whereIn('status', [ReservationStatus::Confirmed, ReservationStatus::CheckedIn])
            ->where('starts_at', '>=', $start)
            ->where('starts_at', '<=', $readinessEnd)
            ->get();
        $activeStayPlaces = Resource::query()
            ->when($propertyId, fn ($query) => $query->where('property_id', $propertyId))
            ->whereHas('category', fn ($query) => $query->where('counts_as_stay', true))
            ->where('is_active', true)
            ->count();
        $occupiedStayPlaces = Allocation::query()
            ->where('status', AllocationStatus::Confirmed)
            ->where('starts_at', '<=', $now->utc())
            ->where('ends_at', '>', $now->utc())
            ->whereHas('resource', fn ($query) => $query
                ->whereHas('category', fn ($category) => $category->where('counts_as_stay', true))
                ->where('is_active', true)
                ->when($propertyId, fn ($query) => $query->where('property_id', $propertyId)))
            ->distinct()
            ->count('resource_id');
        $openTasks = $this->taskQuery()
            ->whereNotIn('status', [TaskStatus::Done, TaskStatus::Cancelled])
            ->count();
        $overdueTasks = $this->taskQuery()
            ->whereNotIn('status', [TaskStatus::Done, TaskStatus::Cancelled])
            ->where('due_at', '<', $now->utc())
            ->count();
        $tasks = $this->taskQuery()
            ->with('assignee:id,name')
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
            ->whereIn('status', [ReservationStatus::Confirmed, ReservationStatus::CheckedIn, ReservationStatus::CheckedOut])
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
            $this->readinessItem('payments', 'Payments', $upcoming, fn (Reservation $reservation) => $this->balance($reservation) <= 0),
            $this->readinessItem('kitchen_brief', 'Kitchen brief', $upcoming, fn (Reservation $reservation) => $reservation->primaryGuest?->preferences !== null),
        ])->concat($this->resourceReadinessItems($upcoming));
        $readinessComplete = (int) $readiness->sum('complete');
        $readinessTotal = (int) $readiness->sum('total');
        $attentionStays = $upcoming
            ->map(fn (Reservation $reservation): ?array => $this->attentionStay($reservation))
            ->filter()
            ->sortBy('starts_at')
            ->take(4)
            ->values();

        return [
            'date' => $now->toDateString(),
            'timezone' => $this->context->tenant()->timezone,
            'arrivals' => $arrivals->count(),
            'departures' => $departures,
            'in_house' => $inHouse,
            'active_resources' => $activeStayPlaces,
            'active_stay_places' => $activeStayPlaces,
            'occupied_stay_places' => $occupiedStayPlaces,
            'open_tasks' => $openTasks,
            'overdue_tasks' => $overdueTasks,
            'occupancy_percent' => $activeStayPlaces > 0 ? round(($occupiedStayPlaces / $activeStayPlaces) * 100, 1) : 0.0,
            'needs_attention' => $upcoming->filter(fn (Reservation $reservation) => $this->needsAttention($reservation))->count(),
            'arrival_parties' => $arrivals
                ->map(fn (Reservation $reservation): array => $this->arrival($reservation))
                ->values()
                ->all(),
            'attention_stays' => $attentionStays->all(),
            'readiness' => [
                'complete' => $readinessComplete,
                'total' => $readinessTotal,
                'percent' => $readinessTotal > 0 ? round(($readinessComplete / $readinessTotal) * 100) : null,
                'items' => $readiness->values()->all(),
            ],
            'tasks' => $tasks->values()->all(),
            'trend' => $this->operationalTrend($now, $propertyId, $activeStayPlaces, $upcoming),
        ];
    }

    /**
     * @param  Collection<int, Reservation>  $upcoming
     * @return array{labels: list<string>, arrivals: list<int>, departures: list<int>, occupancy_percent: list<float>, attention: list<int>, work_due: list<int>}
     */
    private function operationalTrend(CarbonImmutable $now, ?string $propertyId, int $activeStayPlaces, Collection $upcoming): array
    {
        $timezone = $this->context->tenant()->timezone;
        $rangeStart = $now->startOfDay()->subDays(6);
        $rangeEnd = $rangeStart->addDays(14);
        $rangeStartUtc = $rangeStart->utc();
        $rangeEndUtc = $rangeEnd->utc();
        $reservations = Reservation::query()
            ->when($propertyId, fn (Builder $query) => $query->where('property_id', $propertyId))
            ->whereIn('status', [ReservationStatus::Confirmed, ReservationStatus::CheckedIn, ReservationStatus::CheckedOut])
            ->where(function (Builder $query) use ($rangeStartUtc, $rangeEndUtc): void {
                $query->where(fn (Builder $starts) => $starts
                    ->where('starts_at', '>=', $rangeStartUtc)
                    ->where('starts_at', '<', $rangeEndUtc))
                    ->orWhere(fn (Builder $ends) => $ends
                        ->where('ends_at', '>=', $rangeStartUtc)
                        ->where('ends_at', '<', $rangeEndUtc));
            })
            ->get(['id', 'starts_at', 'ends_at']);
        $allocations = Allocation::query()
            ->where('status', AllocationStatus::Confirmed)
            ->where('starts_at', '<', $rangeEndUtc)
            ->where('ends_at', '>', $rangeStartUtc)
            ->whereHas('resource', fn (Builder $query) => $query
                ->whereHas('category', fn (Builder $category) => $category->where('counts_as_stay', true))
                ->where('is_active', true)
                ->when($propertyId, fn (Builder $scope) => $scope->where('property_id', $propertyId)))
            ->get(['resource_id', 'starts_at', 'ends_at']);
        $work = $this->taskQuery()
            ->whereNotIn('status', [TaskStatus::Done, TaskStatus::Cancelled])
            ->where('due_at', '>=', $rangeStartUtc)
            ->where('due_at', '<', $rangeEndUtc)
            ->get(['due_at']);
        $days = collect(range(0, 13))->map(fn (int $offset): CarbonImmutable => $rangeStart->addDays($offset));
        $attentionDays = collect(range(0, 7))->map(fn (int $offset): CarbonImmutable => $now->startOfDay()->addDays($offset));

        return [
            'labels' => $days->map(fn (CarbonImmutable $day): string => $day->format('M j'))->all(),
            'arrivals' => $days->map(fn (CarbonImmutable $day): int => $reservations
                ->filter(fn (Reservation $reservation): bool => $reservation->starts_at->timezone($timezone)->isSameDay($day))
                ->count())->all(),
            'departures' => $days->map(fn (CarbonImmutable $day): int => $reservations
                ->filter(fn (Reservation $reservation): bool => $reservation->ends_at->timezone($timezone)->isSameDay($day))
                ->count())->all(),
            'occupancy_percent' => $days->map(function (CarbonImmutable $day) use ($activeStayPlaces, $allocations): float {
                if ($activeStayPlaces === 0) {
                    return 0.0;
                }

                $dayStart = $day->utc();
                $dayEnd = $day->addDay()->utc();
                $occupied = $allocations
                    ->filter(fn (Allocation $allocation): bool => $allocation->starts_at < $dayEnd && $allocation->ends_at > $dayStart)
                    ->pluck('resource_id')
                    ->unique()
                    ->count();

                return round(($occupied / $activeStayPlaces) * 100, 1);
            })->all(),
            'attention' => $attentionDays->map(fn (CarbonImmutable $day): int => $upcoming
                ->filter(fn (Reservation $reservation): bool => $reservation->starts_at->timezone($timezone)->isSameDay($day) && $this->needsAttention($reservation))
                ->count())->all(),
            'work_due' => $days->map(fn (CarbonImmutable $day): int => $work
                ->filter(fn (OperationalTask $task): bool => $task->due_at?->timezone($timezone)->isSameDay($day) === true)
                ->count())->all(),
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

    /** @return Builder<OperationalTask> */
    private function taskQuery(): Builder
    {
        $membership = $this->context->membership();
        $user = auth()->user();

        if (! $user instanceof User || $membership?->role?->canManageOperations() !== true) {
            return OperationalTask::query()->whereRaw('1 = 0');
        }

        return $this->taskAccess->scope(OperationalTask::query(), $user, $membership->role);
    }

    private function hasStayPlace(Reservation $reservation): bool
    {
        return $reservation->allocations->contains(
            fn (Allocation $allocation) => $allocation->status !== AllocationStatus::Released
                && ($allocation->resource?->countsAsStay() === true
                    || $allocation->requestedCategory?->counts_as_stay === true),
        );
    }

    private function needsAttention(Reservation $reservation): bool
    {
        return $this->attentionReasons($reservation) !== [];
    }

    /** @return list<string> */
    private function attentionReasons(Reservation $reservation): array
    {
        return array_values(array_filter([
            $reservation->primary_guest_id === null ? 'Guest details' : null,
            ...$this->missingResourceReasons($reservation),
            $this->balance($reservation) > 0 ? 'Payment balance' : null,
            $reservation->primaryGuest?->preferences === null ? 'Kitchen brief' : null,
        ]));
    }

    /** @return array<string, mixed>|null */
    private function attentionStay(Reservation $reservation): ?array
    {
        $reasons = $this->attentionReasons($reservation);

        if ($reasons === []) {
            return null;
        }

        return $this->arrival($reservation) + ['reasons' => $reasons];
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
        $hasStayPlace = $this->hasStayPlace($reservation);
        $readiness = ! $hasStayPlace ? 'blocked' : ($this->needsAttention($reservation) ? 'attention' : 'ready');
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
            'stay_place_names' => $reservation->allocations
                ->filter(fn (Allocation $allocation): bool => $allocation->status !== AllocationStatus::Released
                    && ($allocation->requestedCategory?->counts_as_stay === true || $allocation->resource?->countsAsStay() === true))
                ->map(fn (Allocation $allocation): string => $allocation->assignmentLabel())
                ->filter()
                ->values()
                ->all(),
        ];

        if ($this->visibility->canSeeGuestIdentity()) {
            $arrival['guest_name'] = $reservation->primaryGuest
                ? trim("{$reservation->primaryGuest->first_name} {$reservation->primaryGuest->last_name}")
                : null;
        }

        return $arrival;
    }

    /** @param Collection<int, Reservation> $reservations */
    private function resourceReadinessItems(Collection $reservations): Collection
    {
        $items = [];
        foreach ($reservations as $reservation) {
            if ($reservation->program_id === null || $reservation->program?->requires_accommodation === true) {
                $key = 'stay_assignments';
                $items[$key] ??= ['key' => $key, 'label' => 'Stay assignments', 'complete' => 0, 'total' => 0];
                $items[$key]['total']++;
                $items[$key]['complete'] += $this->hasStayPlace($reservation) ? 1 : 0;
            }
            $requirements = $reservation->program_id === null ? collect() : $reservation->program->requirements;
            foreach ($requirements as $requirement) {
                $key = 'category_'.$requirement->resource_category_id;
                $items[$key] ??= ['key' => $key, 'label' => $requirement->category->name.' assignments', 'complete' => 0, 'total' => 0];
                $items[$key]['total']++;
                $required = $requirement->quantityForParty(max(1, $reservation->adults + $reservation->children));
                $items[$key]['complete'] += $this->programRequirements->assignedQuantity($reservation, $requirement) >= $required ? 1 : 0;
            }
        }

        return collect(array_values($items));
    }

    /** @return list<string> */
    private function missingResourceReasons(Reservation $reservation): array
    {
        $reasons = [];
        if (($reservation->program_id === null || $reservation->program?->requires_accommodation === true) && ! $this->hasStayPlace($reservation)) {
            $reasons[] = 'Stay assignment';
        }
        $requirements = $reservation->program_id === null ? collect() : $reservation->program->requirements;
        foreach ($requirements as $requirement) {
            $required = $requirement->quantityForParty(max(1, $reservation->adults + $reservation->children));
            $assigned = $this->programRequirements->assignedQuantity($reservation, $requirement);
            if ($assigned < $required) {
                $reasons[] = $requirement->category->name." assignment ({$assigned}/{$required})";
            }
        }

        return $reasons;
    }
}
