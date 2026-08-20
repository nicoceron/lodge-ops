<?php

namespace App\Services\Projections;

use App\Enums\AllocationStatus;
use App\Enums\MembershipRole;
use App\Enums\ResourceKind;
use App\Models\Allocation;
use App\Models\OperationalTask;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\ResourceBlock;
use App\Models\ServiceOccurrence;
use App\Models\User;
use App\Support\Projections\StaffProjectionVisibility;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CalendarProjectionService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly StaffProjectionVisibility $visibility,
    ) {}

    /** @return array<string, mixed> */
    public function build(
        CarbonImmutable $start,
        CarbonImmutable $end,
        User $user,
        ?string $requestedPropertyId = null,
    ): array {
        $role = $this->context->membership()?->role;
        if ($start->diffInDays($end) > 92) {
            throw ValidationException::withMessages(['end' => 'Calendar windows may not exceed 92 days.']);
        }

        $membershipPropertyId = $this->context->propertyScopeId();
        if ($membershipPropertyId !== null && $requestedPropertyId !== null && $requestedPropertyId !== $membershipPropertyId) {
            throw ValidationException::withMessages(['property_id' => 'The property is outside your active membership scope.']);
        }
        $propertyId = $membershipPropertyId ?? $requestedPropertyId;
        $timezone = $propertyId === null
            ? $this->context->tenant()->timezone
            : (Property::query()->whereKey($propertyId)->value('timezone') ?? $this->context->tenant()->timezone);
        $isGuide = $role === MembershipRole::Guide;
        $guideResourceIds = $isGuide
            ? Resource::query()
                ->when($propertyId, fn ($query) => $query->where('property_id', $propertyId))
                ->whereHas('category', fn ($query) => $query->where('kind', ResourceKind::Crew))
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->pluck('id')
            : collect();
        $resources = Resource::query()
            ->with('category')
            ->when($propertyId, fn ($query) => $query->where('property_id', $propertyId))
            ->when($isGuide, fn ($query) => $query->whereIn('id', $guideResourceIds))
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->sortBy(fn (Resource $resource): array => [$resource->kind()->value, $resource->categoryName(), $resource->name])
            ->values();
        $allocations = Allocation::query()
            ->with(['resource.category', 'reservation:id,confirmation_number'])
            ->where('status', '!=', AllocationStatus::Released)
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->when($propertyId, fn ($query) => $query->whereHas('reservation', fn ($query) => $query->where('property_id', $propertyId)))
            ->when($isGuide, fn ($query) => $query->whereIn('resource_id', $guideResourceIds))
            ->get();
        $events = collect();

        Reservation::query()
            ->with(['primaryGuest:id,first_name,last_name', 'program:id,name,display_color'])
            ->when($propertyId, fn ($query) => $query->where('property_id', $propertyId))
            ->when($isGuide, fn ($query) => $query->whereHas('allocations', fn ($query) => $query
                ->whereIn('resource_id', $guideResourceIds)
                ->where('status', '!=', AllocationStatus::Released)))
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->get()
            ->each(function (Reservation $reservation) use ($events, $allocations): void {
                $title = $reservation->confirmation_number;

                if ($this->visibility->canSeeGuestIdentity() && $reservation->primaryGuest) {
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
                    'program_id' => $reservation->program_id,
                    'program' => $reservation->program ? [
                        'id' => $reservation->program->id,
                        'name' => $reservation->program->name,
                        'display_color' => $reservation->program->display_color,
                    ] : null,
                    'display_color' => $reservation->program?->display_color,
                    'is_buyout' => $allocations
                        ->where('reservation_id', $reservation->id)
                        ->contains(fn (Allocation $allocation): bool => $allocation->resource?->isBuyout() === true),
                    'resource_ids' => $allocations
                        ->where('reservation_id', $reservation->id)
                        ->pluck('resource_id')
                        ->filter()
                        ->unique()
                        ->values(),
                ]);
            });

        $blocks = ResourceBlock::query()
            ->with('resource:id,property_id,is_buyout,attributes')
            ->whereHas('resource', fn ($query) => $query->when($propertyId, fn ($query) => $query->where('property_id', $propertyId)))
            ->when($isGuide, fn ($query) => $query->whereIn('resource_id', $guideResourceIds))
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->get();
        $blocks->each(fn (ResourceBlock $block) => $events->push([
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
            ->with([
                'program:id,name,display_color',
                'allocations' => fn ($query) => $query
                    ->select(['id', 'service_occurrence_id', 'resource_id'])
                    ->when($isGuide, fn ($query) => $query->whereIn('resource_id', $guideResourceIds)),
            ])
            ->when($propertyId, fn ($query) => $query->where('property_id', $propertyId))
            ->when($isGuide, fn ($query) => $query->whereHas('allocations', fn ($query) => $query
                ->whereIn('resource_id', $guideResourceIds)
                ->where('status', '!=', AllocationStatus::Released)))
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
                'program_id' => $occurrence->program_id,
                'display_color' => $occurrence->program->display_color,
                'resource_ids' => $occurrence->allocations->pluck('resource_id')->filter()->unique()->values(),
            ]));

        OperationalTask::query()
            ->when($propertyId, fn ($query) => $query->where('property_id', $propertyId))
            ->when($isGuide, fn ($query) => $query->where('assignee_id', $user->id))
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

        $conflictFacts = $this->conflictFacts($allocations, $blocks);

        return [
            'data' => $events->sortBy('start')->values(),
            'range' => [
                'start' => $start->toIso8601String(),
                'end' => $end->toIso8601String(),
                'timezone' => $timezone,
            ],
            'resources' => $resources->map(fn (Resource $resource): array => [
                'id' => $resource->id,
                'property_id' => $resource->property_id,
                'name' => $resource->name,
                'code' => $resource->code,
                'kind' => $resource->kind()->value,
                'category_slug' => $resource->categorySlug(),
                'category' => $resource->categoryName(),
                'capacity' => $resource->capacity,
                'user_id' => $resource->user_id,
                'is_buyout' => $resource->isBuyout(),
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
                'hard_conflicts' => $conflictFacts->count(),
                'hard_conflict_reservation_ids' => $conflictFacts->flatMap(fn (array $fact) => $fact['reservation_ids'])->filter()->unique()->values()->all(),
                'hard_conflict_facts' => $conflictFacts->values()->all(),
                'unassigned_reservations' => $unassignedReservations,
                'suggestions' => $unassignedReservations,
            ],
        ];
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

                return max(0, $allocationStart->diffInSeconds($allocationEnd)) * $allocation->quantity;
            });

        return (int) min(100, round(($usedSeconds / ($rangeSeconds * max(1, $resource->capacity))) * 100));
    }

    /**
     * @param  Collection<int, Allocation>  $allocations
     * @param  Collection<int, ResourceBlock>  $blocks
     */
    private function conflictFacts(Collection $allocations, Collection $blocks): Collection
    {
        $facts = collect();

        foreach ($allocations->whereNotNull('resource_id')->groupBy('resource_id') as $resourceAllocations) {
            $ordered = $resourceAllocations->sortBy('starts_at')->values();
            $active = collect();
            foreach ($ordered as $allocation) {
                $active = $active->filter(fn (Allocation $candidate): bool => $candidate->ends_at > $allocation->starts_at);
                $resource = $allocation->getRelation('resource');
                $capacity = max(1, $resource instanceof Resource ? $resource->capacity : 1);
                if ($active->sum('quantity') + $allocation->quantity > $capacity) {
                    $facts->push([
                        'type' => 'capacity_overlap',
                        'resource_id' => $allocation->resource_id,
                        'reservation_ids' => $active->pluck('reservation_id')->push($allocation->reservation_id)->filter()->unique()->values()->all(),
                    ]);
                }
                $active->push($allocation);
            }
        }

        $buyouts = $allocations->filter(fn (Allocation $allocation): bool => $allocation->resource?->isBuyout() === true);
        foreach ($buyouts as $buyout) {
            if ($allocations->contains(fn (Allocation $candidate): bool => $candidate->id !== $buyout->id
                && $candidate->reservation_id !== $buyout->reservation_id
                && $candidate->resource?->property_id === $buyout->resource?->property_id
                && $candidate->starts_at < $buyout->ends_at
                && $candidate->ends_at > $buyout->starts_at)) {
                $facts->push([
                    'type' => 'property_buyout_overlap',
                    'resource_id' => $buyout->resource_id,
                    'reservation_ids' => $allocations->filter(fn (Allocation $candidate): bool => $candidate->id === $buyout->id
                        || ($candidate->reservation_id !== $buyout->reservation_id
                            && $candidate->resource?->property_id === $buyout->resource?->property_id
                            && $candidate->starts_at < $buyout->ends_at
                            && $candidate->ends_at > $buyout->starts_at))
                        ->pluck('reservation_id')->filter()->unique()->values()->all(),
                ]);
            }
        }

        foreach ($blocks as $block) {
            if ($allocations->contains(function (Allocation $allocation) use ($block): bool {
                $resource = $allocation->getRelation('resource');
                $samePropertyBuyout = $resource instanceof Resource
                    && $resource->property_id === $block->resource->property_id
                    && ($block->resource->isBuyout() || $resource->isBuyout());

                return ($allocation->resource_id === $block->resource_id || $samePropertyBuyout)
                    && $allocation->starts_at < $block->ends_at
                    && $allocation->ends_at > $block->starts_at;
            })) {
                $facts->push([
                    'type' => $block->resource->isBuyout() ? 'property_buyout_block' : 'resource_block',
                    'resource_id' => $block->resource_id,
                    'resource_block_id' => $block->id,
                    'reservation_ids' => $allocations->filter(function (Allocation $allocation) use ($block): bool {
                        $resource = $allocation->resource;
                        $samePropertyBuyout = $resource->property_id === $block->resource->property_id
                            && ($block->resource->isBuyout() || $resource->isBuyout());

                        return ($allocation->resource_id === $block->resource_id || $samePropertyBuyout)
                            && $allocation->starts_at < $block->ends_at && $allocation->ends_at > $block->starts_at;
                    })->pluck('reservation_id')->filter()->unique()->values()->all(),
                ]);
            }
        }

        return $facts->unique(fn (array $fact): string => $fact['type'].'|'.($fact['resource_id'] ?? '').'|'.implode(',', $fact['reservation_ids']))->values();
    }
}
