<?php

namespace App\Services\Projections;

use App\Enums\AllocationStatus;
use App\Enums\MembershipRole;
use App\Enums\ReservationStatus;
use App\Enums\ResourceKind;
use App\Enums\TaskStatus;
use App\Models\Allocation;
use App\Models\Guest;
use App\Models\Membership;
use App\Models\OperationalTask;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\ServiceOccurrence;
use App\Models\User;
use App\Support\Projections\StaffProjectionVisibility;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class OperationsProjectionService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly StaffProjectionVisibility $visibility,
    ) {}

    /** @return array<string, mixed> */
    public function build(User $user): array
    {
        $now = CarbonImmutable::now($this->context->tenant()->timezone);
        $start = $now->startOfDay()->utc();
        $end = $now->addDay()->startOfDay()->utc();
        $tomorrowEnd = $now->addDays(2)->startOfDay()->utc();
        $activeStatuses = [ReservationStatus::Confirmed, ReservationStatus::CheckedIn];
        $role = $this->visibility->role();
        abort_unless($role instanceof MembershipRole, 403);
        $propertyId = $this->context->membership()?->property_id;
        $userId = (int) $user->getAuthIdentifier();
        $managerialRoles = [MembershipRole::Administrator, MembershipRole::Manager, MembershipRole::Operations];
        $canSeeKitchen = in_array($role, [...$managerialRoles, MembershipRole::Kitchen], true);
        $canSeeGuides = in_array($role, [...$managerialRoles, MembershipRole::Guide], true);
        $canSeeHousekeeping = in_array($role, [...$managerialRoles, MembershipRole::Housekeeping], true);

        $occurrences = ServiceOccurrence::query()
            ->with([
                'program:id,name',
                'allocations' => fn ($query) => $query
                    ->where('status', '!=', AllocationStatus::Released->value)
                    ->with([
                        'resource.category',
                        'resource.user.memberships',
                        'reservation:id,primary_guest_id,adults,children,confirmation_number',
                    ]),
            ])
            ->when($propertyId, fn (Builder $query) => $query->where('property_id', $propertyId))
            ->where('starts_at', '>=', $end)
            ->where('starts_at', '<', $tomorrowEnd)
            ->where('is_cancelled', false)
            ->when($role === MembershipRole::Guide, fn (Builder $query) => $query->whereHas(
                'allocations',
                fn (Builder $allocation) => $allocation
                    ->where('status', '!=', AllocationStatus::Released->value)
                    ->whereHas('resource', fn (Builder $resource) => $resource
                        ->where('user_id', $userId)
                        ->whereHas('category', fn (Builder $category) => $category->where('kind', ResourceKind::Crew))),
            ))
            ->orderBy('starts_at')
            ->get();
        $guideReservationIds = $occurrences
            ->flatMap(fn (ServiceOccurrence $occurrence) => $occurrence->allocations->pluck('reservation_id'))
            ->filter()
            ->unique()
            ->values();

        $tasks = OperationalTask::query()
            ->with('assignee:id,name')
            ->when($propertyId, fn (Builder $query) => $query->where('property_id', $propertyId))
            ->when(
                ! in_array($role, $managerialRoles, true),
                fn (Builder $query) => $this->applyTaskRoleScope($query, $role, $userId, $guideReservationIds),
            )
            ->where(function ($query) use ($end): void {
                $query->whereNull('due_at')->orWhere('due_at', '<', $end);
            })
            ->orderByRaw("case priority when 'urgent' then 1 when 'high' then 2 when 'normal' then 3 else 4 end")
            ->orderBy('due_at')
            ->limit(30)
            ->get();
        $operationalReservations = Reservation::query()
            ->with([
                'primaryGuest:id,first_name,last_name,preferences',
                'guests:id,first_name,last_name,preferences',
                'guestPortalProfiles:id,reservation_id,guest_id,preferences',
            ])
            ->when($propertyId, fn (Builder $query) => $query->where('property_id', $propertyId))
            ->whereIn('status', $activeStatuses)
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->when($role === MembershipRole::Guide, fn (Builder $query) => $query->whereIn('id', $guideReservationIds))
            ->orderBy('starts_at')
            ->get();
        $arrivals = $operationalReservations->filter(
            fn (Reservation $reservation) => $reservation->starts_at->greaterThanOrEqualTo($start)
                && $reservation->starts_at->lessThan($end),
        );
        $departures = $operationalReservations->filter(
            fn (Reservation $reservation) => $reservation->ends_at->greaterThanOrEqualTo($start)
                && $reservation->ends_at->lessThan($end),
        );
        $stayovers = $operationalReservations->filter(
            fn (Reservation $reservation) => $reservation->starts_at->lessThan($start)
                && $reservation->ends_at->greaterThan($end),
        );
        $places = $canSeeHousekeeping
            ? Resource::query()
                ->with('category:id,kind,name')
                ->when($propertyId, fn (Builder $query) => $query->where('property_id', $propertyId))
                ->where('is_active', true)
                ->whereHas('category', fn (Builder $query) => $query->where('kind', ResourceKind::Place))
                ->orderBy('name')
                ->get()
                ->map(fn (Resource $resource): array => [
                    'id' => $resource->id,
                    'name' => $resource->name,
                    'category' => $resource->category->name,
                    'status' => $resource->housekeeping_status === null ? 'untracked' : $resource->housekeeping_status->value,
                    'updated_at' => $resource->housekeeping_updated_at?->toIso8601String(),
                ])
                ->values()
            : collect();

        $taskItems = $tasks->map(fn (OperationalTask $task): array => [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status->value,
            'priority' => $task->priority,
            'due_at' => $task->due_at?->toIso8601String(),
            'owner_initials' => $this->initials($task->assignee?->name),
        ]);

        return [
            'date' => $now->toDateString(),
            'timezone' => $this->context->tenant()->timezone,
            'role_scope' => [
                'role' => $role->value,
                'visible_sections' => array_values(array_filter([
                    'tasks',
                    'arrivals',
                    $canSeeKitchen ? 'kitchen' : null,
                    $canSeeGuides ? 'guide_assignments' : null,
                    $canSeeHousekeeping ? 'housekeeping' : null,
                ])),
            ],
            'privacy' => [
                'can_view_guest_identity' => $this->visibility->canSeeGuestIdentity(),
                'can_view_dietary_details' => $this->visibility->canSeeDietaryDetails(),
                'restricted_fields' => array_values(array_filter([
                    $this->visibility->canSeeGuestIdentity() ? null : 'arrivals.guest_name',
                    $this->visibility->canSeeDietaryDetails() ? null : 'arrivals.dietary',
                ])),
            ],
            'readiness' => [
                'complete' => $tasks->whereIn('status', [TaskStatus::Done, TaskStatus::Cancelled, TaskStatus::Superseded])->count(),
                'total' => $tasks->count(),
                'open' => $tasks->whereNotIn('status', [TaskStatus::Done, TaskStatus::Cancelled, TaskStatus::Superseded])->count(),
            ],
            'tasks' => $taskItems,
            'arrivals' => $arrivals->map(fn (Reservation $reservation) => $this->arrival($reservation, $this->visibility))->values(),
            'kitchen' => [
                'available' => $canSeeKitchen,
                'guest_count' => $canSeeKitchen
                    ? $operationalReservations->sum(fn (Reservation $reservation) => $reservation->adults + $reservation->children)
                    : 0,
                'restrictions' => $canSeeKitchen ? $this->restrictions($operationalReservations) : [],
                'identity_restricted' => ! $this->visibility->canSeeGuestIdentity(),
                'dietary_details_restricted' => ! $this->visibility->canSeeDietaryDetails(),
            ],
            'guide_assignments' => $canSeeGuides
                ? $occurrences->map(fn (ServiceOccurrence $occurrence) => $this->guideAssignment(
                    $occurrence,
                    $role === MembershipRole::Guide ? $userId : null,
                ))->values()
                : [],
            'housekeeping' => [
                'available' => $canSeeHousekeeping,
                'arrivals' => $canSeeHousekeeping ? $arrivals->count() : 0,
                'turnovers' => $canSeeHousekeeping ? $departures->count() : 0,
                'stayovers' => $canSeeHousekeeping ? $stayovers->count() : 0,
                'places' => $places->all(),
                'needs_attention' => $canSeeHousekeeping
                    ? $places->whereIn('status', ['dirty', 'in_progress', 'out_of_service', 'untracked'])->count()
                    : 0,
                'focus' => $canSeeHousekeeping
                    ? ($tasks->firstWhere('priority', 'urgent')?->title
                        ?? $tasks->firstWhere('priority', 'high')?->title)
                    : null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function arrival(Reservation $reservation, StaffProjectionVisibility $visibility): array
    {
        $arrival = [
            'id' => $reservation->id,
            'confirmation_number' => $reservation->confirmation_number,
            'starts_at' => $reservation->starts_at->toIso8601String(),
            'ends_at' => $reservation->ends_at->toIso8601String(),
            'party_size' => $reservation->adults + $reservation->children,
            'status' => $reservation->status->value,
        ];

        if ($this->visibility->canSeeGuestIdentity()) {
            $arrival['guest_name'] = $reservation->primaryGuest
                ? trim("{$reservation->primaryGuest->first_name} {$reservation->primaryGuest->last_name}")
                : null;
        }

        if ($this->visibility->canSeeDietaryDetails()) {
            $arrival['dietary'] = $this->reservationDietaryLabels($reservation);
        }

        return $arrival;
    }

    /** @param Collection<int, Reservation> $reservations @return list<array<string, mixed>> */
    private function restrictions(Collection $reservations): array
    {
        return $reservations
            ->flatMap(fn (Reservation $reservation) => $this->reservationRestrictionLabels($reservation))
            ->countBy()
            ->map(fn (int $count, string $label): array => [
                'label' => $label,
                'count' => $count,
                'serious' => str_contains(strtolower($label), 'allerg') || str_contains(strtolower($label), 'severe'),
            ])
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function reservationRestrictionLabels(Reservation $reservation): array
    {
        $knownGuestIds = $this->reservationGuests($reservation)->pluck('id');
        $guestLabels = $this->reservationGuests($reservation)->flatMap(function (Guest $guest) use ($reservation): array {
            return collect($this->dietaryLabels($guest->preferences))
                ->concat($reservation->guestPortalProfiles
                    ->where('guest_id', $guest->id)
                    ->flatMap(fn ($profile) => $this->dietaryLabels($profile->preferences)))
                ->unique(fn (string $label) => strtolower($label))
                ->values()
                ->all();
        });

        return $guestLabels
            ->concat($reservation->guestPortalProfiles
                ->whereNotIn('guest_id', $knownGuestIds)
                ->flatMap(fn ($profile) => $this->dietaryLabels($profile->preferences)))
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function reservationDietaryLabels(Reservation $reservation): array
    {
        return $this->reservationGuests($reservation)
            ->flatMap(fn (Guest $guest) => $this->dietaryLabels($guest->preferences))
            ->concat($reservation->guestPortalProfiles
                ->flatMap(fn ($profile) => $this->dietaryLabels($profile->preferences)))
            ->unique(fn (string $label) => strtolower($label))
            ->values()
            ->all();
    }

    /** @return Collection<int, Guest> */
    private function reservationGuests(Reservation $reservation): Collection
    {
        return collect([$reservation->primaryGuest])
            ->filter()
            ->concat($reservation->guests)
            ->unique('id')
            ->values();
    }

    /** @param array<string, mixed>|null $preferences @return list<string> */
    private function dietaryLabels(?array $preferences): array
    {
        if ($preferences === null) {
            return [];
        }

        $values = collect([
            data_get($preferences, 'dietary'),
            data_get($preferences, 'dietary_style'),
            data_get($preferences, 'dietary_requirements'),
            data_get($preferences, 'allergies'),
        ])->filter()->flatMap(function (mixed $value): array {
            if (is_array($value)) {
                return array_values(array_filter($value, 'is_string'));
            }

            return is_string($value) ? preg_split('/[,;]+/', $value) ?: [] : [];
        });

        return $values
            ->map(fn (string $value) => trim($value))
            ->filter()
            ->unique(fn (string $value) => strtolower($value))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function guideAssignment(ServiceOccurrence $occurrence, ?int $guideUserId = null): array
    {
        $guide = $occurrence->allocations
            ->first(fn (Allocation $allocation) => $allocation->resource?->category->kind === ResourceKind::Crew
                && $allocation->resource->user?->memberships->contains(
                    fn (Membership $membership): bool => $membership->role === MembershipRole::Guide && $membership->is_active,
                ) === true
                && ($guideUserId === null || (int) $allocation->resource->user_id === $guideUserId))
            ?->resource;
        $reservations = $occurrence->allocations->pluck('reservation')->filter()->unique('id');

        return [
            'id' => $occurrence->id,
            'guide_resource_id' => $guide?->id,
            'guide' => $guide?->name,
            'program' => $occurrence->program->name,
            'starts_at' => $occurrence->starts_at->toIso8601String(),
            'party_size' => $reservations->sum(fn (Reservation $reservation) => $reservation->adults + $reservation->children),
            'status' => $guide ? 'confirmed' : 'action_needed',
        ];
    }

    /** @param Collection<int, string> $guideReservationIds */
    private function applyTaskRoleScope(
        Builder $query,
        MembershipRole $role,
        int $userId,
        Collection $guideReservationIds,
    ): void {
        $query->where(function (Builder $tasks) use ($role, $userId, $guideReservationIds): void {
            $tasks->where('assignee_id', $userId)
                ->orWhere(function (Builder $unassigned) use ($role, $guideReservationIds): void {
                    $unassigned->whereNull('assignee_id')
                        ->where(function (Builder $roleTasks) use ($role): void {
                            $roleTasks
                                ->whereHas('programTaskTemplate', fn (Builder $template) => $template->where('assignee_role', $role->value))
                                ->orWhere('metadata->assignee_role', $role->value)
                                ->orWhere('metadata->role', $role->value)
                                ->orWhere('metadata->team', $role->value);
                        });

                    if ($role === MembershipRole::Guide) {
                        $unassigned->whereIn('reservation_id', $guideReservationIds);
                    }
                });
        });
    }

    private function initials(?string $name): string
    {
        if (! $name) {
            return '—';
        }

        return collect(preg_split('/\s+/', trim($name)) ?: [])
            ->filter()
            ->take(2)
            ->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
    }
}
